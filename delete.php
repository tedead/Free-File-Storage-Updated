<?php
declare(strict_types=1);

/**
 * Delete handler.
 */

require_once __DIR__ . '/bootstrap.php';

require_post();
csrf_verify();

$userId = require_login();
$fileId = (string) ($_POST['file_id'] ?? '');

if (!is_uuid($fileId)) {
    flash('error', 'That file could not be found.');
    redirect('files.php');
}

$storedPath = null;
$deleted    = 0;

// The session's user id is passed as the owner -- never a value from the form.
// ffs.DeleteFile refuses to touch a row that is not linked to that user, so a
// guessed or borrowed file id changes nothing.
db_call(
    'DeleteFile',
    [$userId, $fileId],
    [
        2 => ['type' => PDO::PARAM_STR, 'length' => 400, 'init' => ''],   // @StoredPath OUTPUT
        3 => ['type' => PDO::PARAM_INT, 'length' => 1,   'init' => 0],    // @Deleted OUTPUT
    ],
    $out
);

$storedPath = $out[2] ?? null;
$deleted    = (int) ($out[3] ?? 0);

if ($deleted !== 1) {
    // Covers both "no such file" and "belongs to someone else"; the user has no
    // business telling those apart.
    flash('error', 'That file could not be found.');
    redirect('files.php');
}

// Null when another user still has the same file linked -- the row is gone for
// this user, but the bytes stay.
if (is_string($storedPath) && $storedPath !== '') {
    storage_delete($storedPath);
}

flash('success', 'File deleted.');
redirect('files.php');
