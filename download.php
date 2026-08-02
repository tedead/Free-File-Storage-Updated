<?php
declare(strict_types=1);

/**
 * Download handler.
 *
 * Replaces fetch.php, which looked the file up, built a public URL to it with
 * getBaseURL(), stashed that in the session and redirected the browser back to
 * the dashboard to show a "Download" link. The link pointed straight at the
 * file inside the document root, so the web server -- not this application --
 * served it: no ownership check at request time, and anything with a .php
 * extension executed instead of downloading.
 *
 * Here the bytes are read by PHP and streamed, and the only way to reach them
 * is through this ownership check.
 */

require_once __DIR__ . '/bootstrap.php';

$userId = require_login();
$fileId = (string) ($_GET['id'] ?? '');

if (!is_uuid($fileId)) {
    flash('error', 'That file could not be found.');
    redirect('files.php');
}

// Scoped by user id inside the procedure: someone else's file id returns null.
$file = db_row('GetUserFile', [$userId, $fileId]);

if ($file === null) {
    flash('error', 'That file could not be found.');
    redirect('files.php');
}

$path = storage_absolute_path((string) $file['StoredPath']);

if ($path === null) {
    error_log('[FFS] missing on disk: ' . $file['StoredPath']);
    flash('error', 'That file is no longer available.');
    redirect('files.php');
}

// bootstrap.php already sent HTML headers; clear them for a binary response.
header_remove('Content-Type');
header_remove('Content-Security-Policy');

$name = safe_display_name((string) $file['Name']);

// Always octet-stream with an attachment disposition. Serving the stored
// content type would let an uploaded .html or .svg run script in this origin
// and read the session cookie of whoever opened it.
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

// Two filename parameters: a stripped-to-ASCII fallback that cannot break out
// of the quoted string, and RFC 5987 filename* which carries the real name for
// browsers that understand it.
$ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'download';

// Also drop the characters Windows forbids in a filename. Not a header-injection
// concern -- control characters are already gone, and a header value is not an
// HTML context -- but a name like <img src=x onerror=alert(1)>.txt otherwise
// arrives verbatim in the Save dialog, where the OS rejects it and the download
// fails for a reason the user cannot act on.
$ascii = str_replace(['"', '\\', '/', ':', '*', '?', '<', '>', '|'], '_', $ascii);
$ascii = trim($ascii) !== '' ? $ascii : 'download';

header(sprintf(
    "Content-Disposition: attachment; filename=\"%s\"; filename*=UTF-8''%s",
    $ascii,
    rawurlencode($name)
));

// Discard buffered output so nothing is prepended to the file's bytes.
while (ob_get_level() > 0) {
    ob_end_clean();
}

// readfile() streams in chunks rather than loading the whole file into memory,
// which matters at the 64 MB upload limit.
readfile($path);
exit;
