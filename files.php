<?php
declare(strict_types=1);

/**
 * Dashboard: upload form plus the signed-in user's files.
 *
 * This was upload.php. Renamed because it is the main page of the site, not
 * just an upload form; upload.php now redirects here.
 */

require_once __DIR__ . '/bootstrap.php';

// Returns the id, or redirects and exits. The old version of this check sent a
// Location header and then kept going, rendering the file list to anonymous
// callers who ignored the redirect.
$userId = require_login();

$files = db_rows('ListUserFiles', [$userId]);

page_header('Your files');
?>

<div class="card">
    <div class="card__header">
        <h1>Upload a file</h1>
        <p>Up to <?= e(format_bytes((int) config('max_upload_bytes'))) ?> per file.</p>
    </div>

    <form method="post" action="submit.php" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <!-- Advisory only: the browser may ignore it and the request can be
             forged, so submit.php enforces the real limit server-side. -->
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) config('max_upload_bytes') ?>">

        <div class="field-row">
            <div class="field">
                <label for="file">File</label>
                <input type="file" id="file" name="file" required>
            </div>

            <div class="field">
                <label for="category">Category</label>
                <select id="category" name="category">
                    <?php foreach (config('categories') as $category): ?>
                        <option value="<?= e($category) ?>"><?= e($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Upload</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="toolbar">
        <h2>Your files</h2>
        <span class="count">
            <?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?>
        </span>
    </div>

    <?php if ($files === []): ?>

        <div class="empty">
            <span class="empty__mark" aria-hidden="true">&#128193;</span>
            <h2>Nothing stored yet</h2>
            <p class="mb-0">Files you upload will be listed here.</p>
        </div>

    <?php else: ?>

        <div class="table-scroll">
            <table class="file-table">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Category</th>
                        <th scope="col">Size</th>
                        <th scope="col">Uploaded</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($files as $file): ?>
                    <?php
                        // Note: these are PHP comments, not <!-- HTML --> ones.
                        // An HTML comment inside this loop is re-sent to the
                        // browser for every row, and one explaining an XSS bug
                        // necessarily contains the payload it describes -- which
                        // is inert inside a comment but still ships the string to
                        // every visitor and trips security scanners.
                        //
                        // Filenames below all pass through e(). A file uploaded
                        // as an <img> tag with an onerror handler used to be
                        // echoed raw here and ran for whoever viewed the list.
                        //
                        // SQL Server returns UNIQUEIDENTIFIER in uppercase, so
                        // this id is uppercase hex. is_uuid() is case-insensitive
                        // and the value round-trips unchanged.
                        $fileId = (string) $file['FileID'];
                    ?>
                    <tr>
                        <td>
                            <span class="file-name">
                                <span class="file-icon" aria-hidden="true"><?= category_icon((string) $file['Category']) ?></span>
                                <span>
                                    <?= e((string) $file['Name']) ?>
                                    <span class="type-hint"><?= e((string) $file['ContentType']) ?></span>
                                </span>
                            </span>
                        </td>

                        <td><span class="pill"><?= e((string) $file['Category']) ?></span></td>

                        <td class="col-size"><?= e(format_bytes((int) $file['Size'])) ?></td>

                        <td class="col-date"><?= e(format_date((string) $file['DateCreated'])) ?></td>

                        <td class="col-actions">
                            <?php
                                // download.php checks ownership and streams the
                                // bytes. The old page linked straight at the file
                                // inside the webroot, which is what made an
                                // uploaded .php file executable.
                            ?>
                            <a class="btn btn--secondary btn--sm"
                               href="download.php?id=<?= urlencode($fileId) ?>">
                                Download
                                <span class="visually-hidden">&nbsp;<?= e((string) $file['Name']) ?></span>
                            </a>

                            <?php
                                // data-confirm rather than onsubmit="": an inline
                                // handler would be blocked by the CSP, so
                                // assets/site.js binds it instead.
                            ?>
                            <form method="post" action="delete.php"
                                  data-confirm="Delete this file? This cannot be undone.">
                                <?= csrf_field() ?>
                                <?php
                                    // The file id alone. The old forms posted the
                                    // user id concatenated with the file id and
                                    // the handler trusted the user id it was
                                    // given, so editing this value in the page
                                    // reached anyone else's files. Ownership now
                                    // comes from the session and is enforced in
                                    // the stored procedure.
                                ?>
                                <input type="hidden" name="file_id" value="<?= e($fileId) ?>">
                                <button type="submit" class="btn btn--danger btn--sm">
                                    Delete
                                    <span class="visually-hidden">&nbsp;<?= e((string) $file['Name']) ?></span>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>
</div>

<?php page_footer(); ?>
