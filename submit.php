<?php
declare(strict_types=1);

/**
 * Upload handler.
 */

require_once __DIR__ . '/bootstrap.php';

require_post();
csrf_verify();

$userId   = require_login();
$category = (string) ($_POST['category'] ?? '');

$result = store_upload($_FILES['file'] ?? [], $userId, $category);

if (!$result['ok']) {
    flash('error', $result['error']);
    redirect('files.php');
}

// The bytes are on disk; record them. One procedure call wrapping both inserts
// in a transaction, rather than the original's two separate connections where a
// failure on the second left a files row nobody owned.
try {
    db_exec('AddFile', [
        $result['file_id'],
        $userId,
        $result['name'],
        $result['size'],
        $result['content_type'],
        $category,
        $result['stored_path'],
    ]);
} catch (Throwable $e) {
    // The transaction rolled back, so nothing is recorded. Remove the orphaned
    // bytes too, otherwise they sit in storage forever, unlisted and unreachable.
    storage_delete($result['stored_path']);

    error_log('[FFS] upload insert failed: ' . $e->getMessage());
    flash('error', 'The file could not be saved. Please try again.');
    redirect('files.php');
}

flash('success', 'Uploaded ' . $result['name'] . '.');
redirect('files.php');
