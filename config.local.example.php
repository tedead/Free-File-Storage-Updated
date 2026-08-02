<?php
declare(strict_types=1);

/**
 * Copy to config.local.php and edit. config.local.php is gitignored.
 *
 * Anything omitted falls back to the environment variable, then to the default
 * in config.php. You only need the keys you actually want to change.
 */

return [
    'db' => [
        'host' => 'localhost\\SQLEXPRESS',   // named instance: escape the backslash
        'port' => 1433,
        'name' => 'file_storage',

        // Leave 'user' out entirely to connect with Windows authentication.
        'user' => 'ffs_app',
        'pass' => 'the password you set in sql/03_security.sql',

        'encrypt' => true,
        // true only for a local dev server with a self-signed certificate.
        // On a real deployment this must be false with a trusted certificate,
        // otherwise the encryption is unauthenticated and defeats its purpose.
        'trust_cert' => true,
    ],

    // Recommended: move this outside the webroot.
    // 'storage_path' => 'C:\\ffs-storage',

    'secure_cookies' => false,   // true once you are serving over HTTPS
    'debug'          => true,    // development only
];
