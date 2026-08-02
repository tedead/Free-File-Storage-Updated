<?php
declare(strict_types=1);

/**
 * Configuration.
 *
 * Nothing secret is hardcoded here. The original had "userPass" pasted into
 * nine different files, so rotating the database password meant a
 * find-and-replace across the codebase.
 *
 * Values are resolved in this order, later winning over earlier:
 *
 *   1. The defaults written below.
 *   2. A .env file next to this one (see .env.example).
 *   3. Real environment variables -- SetEnv in Apache, the application settings
 *      in IIS, or the shell. These beat .env so a deployment can override a
 *      checked-out file without editing it.
 *   4. config.local.php, if present. An explicit local override that wins over
 *      everything.
 *
 * Pick one of .env or config.local.php and stay with it; using both just makes
 * it harder to work out where a value came from.
 *
 * Both are gitignored. Neither belongs in source control -- but note that
 * config.local.php is PHP, so requesting it over HTTP executes it and returns
 * nothing, whereas a .env is plain text and would be served verbatim if the
 * deny rules in .htaccess were ever missing. Prefer .env only when it sits
 * outside the webroot, or when your tooling expects that format.
 */

function config(?string $key = null, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        load_env_file(__DIR__ . DIRECTORY_SEPARATOR . '.env');

        $config = [
            'db' => [
                'host' => env('FFS_DB_HOST', 'localhost'),
                'port' => (int) env('FFS_DB_PORT', '1433'),
                'name' => env('FFS_DB_NAME', 'file_storage'),

                // Leave user empty to use Windows authentication (no password
                // anywhere). Only set these for a SQL Server login.
                'user' => env('FFS_DB_USER', ''),
                'pass' => env('FFS_DB_PASS', ''),

                // Encrypt defaults on: the driver's default changed to on in
                // msodbcsql 18 and connections should be encrypted regardless.
                'encrypt'    => env_bool('FFS_DB_ENCRYPT', true),
                // Only true for a self-signed dev certificate. See README.
                'trust_cert' => env_bool('FFS_DB_TRUST_CERT', false),
            ],

            // Ideally somewhere outside the webroot entirely. The default keeps
            // the original location so an existing install still works, and the
            // .htaccess / web.config in that directory block direct access.
            'storage_path' => env('FFS_STORAGE_PATH', __DIR__ . DIRECTORY_SEPARATOR . 'User Directories'),

            'categories' => ['Application', 'Utility', 'Compressed', 'Misc'],

            'max_upload_bytes' => (int) env('FFS_MAX_UPLOAD_BYTES', (string) (64 * 1024 * 1024)),

            'min_password_length' => 12,

            // How long a success/notice flash stays on screen, in milliseconds.
            // Error flashes ignore this and stay until dismissed by hand -- see
            // render_flashes() for why. 0 disables auto-dismiss entirely.
            'flash_dismiss_ms' => (int) env('FFS_FLASH_DISMISS_MS', '6000'),

            // bcrypt work factor. Each step doubles the time to compute a hash,
            // which doubles an offline cracker's cost per guess if the database
            // is ever stolen.
            //
            // Measured on this machine (PHP 8.3):
            //   10 (PHP's default)  44 ms
            //   11                  83 ms
            //   12                 164 ms   <- chosen
            //   13                 324 ms
            //   14                 657 ms
            //
            // 12 is the usual recommendation and sits in the 100-250 ms band:
            // imperceptible at sign-in, four times the attacker's cost versus
            // the default. Higher is not automatically better -- every login
            // pays this on the server, so 14 would both be felt by users and
            // hand an attacker a cheap way to burn your CPU by spamming logins.
            //
            // Raising this later is safe: existing users are re-hashed
            // transparently the next time they sign in. See authenticate_user().
            'bcrypt_cost' => (int) env('FFS_BCRYPT_COST', '12'),

            // Idle timeout, then a hard cap regardless of activity.
            'session_idle_seconds'     => 30 * 60,
            'session_absolute_seconds' => 12 * 60 * 60,

            // Set true when the site is served over HTTPS (it should be).
            'secure_cookies' => env_bool('FFS_SECURE_COOKIES', false),

            // Show PHP errors on screen. Never enable in production: the
            // original leaked connection details through mysqli_error() output.
            'debug' => env_bool('FFS_DEBUG', false),
        ];

        $local = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
        if (is_file($local)) {
            $config = array_replace_recursive($config, require $local);
        }
    }

    if ($key === null) {
        return $config;
    }

    // Supports dotted lookups: config('db.host')
    $value = $config;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

/**
 * Load a .env file into the environment.
 *
 * Deliberately small: no dependency, and it only does the parts of the dotenv
 * format that actually earn their keep -- comments, blank lines, KEY=VALUE, and
 * optional surrounding quotes. There is no variable interpolation, because a
 * password containing a literal $ would then silently expand to something else.
 *
 * An existing real environment variable is never overwritten. That is the usual
 * dotenv rule and it is what makes a deployment able to override a checked-out
 * file: the server sets FFS_DB_PASS, and the .env sitting on disk loses.
 */
function load_env_file(string $path): void
{
    static $seen = [];

    if (isset($seen[$path])) {
        return;
    }
    $seen[$path] = true;

    if (!is_readable($path)) {
        return;                       // absent is normal, not an error
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $split = strpos($line, '=');
        if ($split === false) {
            continue;
        }

        $name  = trim(substr($line, 0, $split));
        $value = trim(substr($line, $split + 1));

        if ($name === '') {
            continue;
        }

        // Strip one matching pair of surrounding quotes, so a value with
        // trailing spaces or a # can be written as "  hunter2 # not a comment".
        $len = strlen($value);
        if ($len >= 2
            && ($value[0] === '"' || $value[0] === "'")
            && $value[$len - 1] === $value[0]) {
            $value = substr($value, 1, -1);
        }

        // A real environment variable always wins.
        if (getenv($name) !== false) {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

function env(string $name, string $default = ''): string
{
    $value = getenv($name);

    return ($value === false || $value === '') ? $default : $value;
}

function env_bool(string $name, bool $default): bool
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
}
