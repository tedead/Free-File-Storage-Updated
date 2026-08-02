<?php
declare(strict_types=1);

/**
 * Upload storage.
 *
 * Layout on disk:   <storage root>/<user id>/<Category>/<file id>.<ext>
 *
 * Three deliberate differences from the original, which wrote to
 * "./User Directories/$user/$category/" . $_FILES["file"]["name"] :
 *
 *   1. The path is built entirely from values this code generates -- a UUID for
 *      the user, a validated category, a UUID for the file. Previously both the
 *      username and the category came straight from the request, and the
 *      filename came from the browser, so a multipart part named
 *      "../../../index.php" wrote wherever it liked.
 *
 *   2. The stored name is a UUID, not the uploaded name. Nothing about the
 *      content influences where it lands.
 *
 *   3. Nothing here is ever served by the web server. Uploads used to be linked
 *      directly and lived under the document root, so uploading shell.php and
 *      clicking the link executed it. download.php streams the bytes instead,
 *      and the storage directory ships with deny rules for Apache and IIS.
 */

/**
 * Absolute path to the storage root, created on first use.
 */
function storage_root(): string
{
    $root = config('storage_path');

    if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
        throw new RuntimeException('The storage directory could not be created.');
    }

    $real = realpath($root);
    if ($real === false) {
        throw new RuntimeException('The storage directory could not be resolved.');
    }

    return $real;
}

/**
 * Is this one of the configured categories?
 *
 * Checked because the category becomes a directory name. The old code dropped
 * $_POST['category'] into the path unvalidated.
 */
function is_valid_category(string $category): bool
{
    return in_array($category, config('categories'), true);
}

/**
 * Extensions that a misconfigured web server might execute rather than serve.
 *
 * This is a backstop, not the defence -- the real protections are that stored
 * files are named <uuid>.<ext>, live outside any served path, and only ever
 * leave through download.php as an attachment. But if someone later moves the
 * storage directory somewhere reachable, this keeps the worst case out.
 */
const EXECUTABLE_EXTENSIONS = [
    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar', 'inc',
    'asp', 'aspx', 'ashx', 'asmx', 'cshtml', 'jsp', 'jspx', 'cfm', 'pl', 'cgi',
    'htaccess', 'htpasswd', 'config',
];

/**
 * Store an uploaded file and return what the database needs to record.
 *
 * @param array $file One entry from $_FILES.
 *
 * @return array{ok: bool, error: ?string, name: ?string, size: ?int,
 *               content_type: ?string, stored_path: ?string}
 */
function store_upload(array $file, string $userId, string $category): array
{
    $fail = static fn(string $message): array => [
        'ok' => false, 'error' => $message,
        'name' => null, 'size' => null, 'content_type' => null, 'stored_path' => null,
    ];

    // The original tested only "error > 0", so UPLOAD_ERR_NO_FILE (4) and a
    // successful upload were both reported to the user as "an unknown error".
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($error !== UPLOAD_ERR_OK) {
        return $fail(match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than the server allows.',
            UPLOAD_ERR_PARTIAL                        => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE                        => 'Please choose a file to upload.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not write the file. Contact the administrator.',
            UPLOAD_ERR_EXTENSION                      => 'A server extension blocked this upload.',
            default                                   => 'The upload failed.',
        });
    }

    // Confirms the path really is a PHP-managed upload and not an arbitrary
    // server path smuggled into the array.
    if (!is_uploaded_file($file['tmp_name'])) {
        return $fail('The upload could not be verified.');
    }

    if (!is_valid_category($category)) {
        return $fail('Please choose a valid category.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return $fail('That file is empty.');
    }
    if ($size > config('max_upload_bytes')) {
        return $fail('That file is larger than the ' . format_bytes(config('max_upload_bytes')) . ' limit.');
    }

    // basename() drops any directory component the browser sent.
    $originalName = safe_display_name((string) ($file['name'] ?? 'upload'));
    $extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($extension !== '' && in_array($extension, EXECUTABLE_EXTENSIONS, true)) {
        return $fail('Files of that type cannot be stored here.');
    }

    // $_FILES['type'] is whatever the browser claimed. Ask the file instead.
    $contentType = detect_content_type($file['tmp_name']);

    $fileId    = uuid4();
    $storedRel = $userId . '/' . $category . '/' . $fileId . ($extension !== '' ? '.' . $extension : '');
    $storedAbs = storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedRel);

    $directory = dirname($storedAbs);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        return $fail('The destination folder could not be created.');
    }

    if (!move_uploaded_file($file['tmp_name'], $storedAbs)) {
        return $fail('The file could not be saved.');
    }

    @chmod($storedAbs, 0640);

    return [
        'ok'           => true,
        'error'        => null,
        'file_id'      => $fileId,
        'name'         => $originalName,
        'size'         => $size,
        'content_type' => $contentType,
        'stored_path'  => $storedRel,
    ];
}

/**
 * Clean up a filename for display and storage in the database.
 *
 * Not a path-safety measure -- the stored path never uses this value. It keeps
 * control characters and directory separators out of what gets shown and sent
 * in the Content-Disposition header.
 */
function safe_display_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    $name = trim($name);

    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'upload';
    }

    return mb_substr($name, 0, 255);
}

/**
 * Determine a content type from the file's own bytes.
 */
function detect_content_type(string $path): string
{
    if (!class_exists('finfo')) {
        return 'application/octet-stream';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $type  = $finfo->file($path);

    return ($type === false || $type === '') ? 'application/octet-stream' : $type;
}

/**
 * Resolve a stored relative path to an absolute one, refusing to escape the root.
 *
 * These paths are generated by store_upload() and never user-supplied, so this
 * should be unreachable -- but a bug or a tampered row should fail closed
 * rather than hand out /Windows/win.ini.
 */
function storage_absolute_path(string $relative): ?string
{
    $root = storage_root();
    $path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));

    if ($path === false || !is_file($path)) {
        return null;
    }

    if (!str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $path;
}

/**
 * Remove a stored file. Missing is not an error -- the row is going either way.
 */
function storage_delete(string $relative): void
{
    $path = storage_absolute_path($relative);

    if ($path !== null) {
        @unlink($path);
    }
}
