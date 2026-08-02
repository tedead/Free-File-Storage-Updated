<?php
declare(strict_types=1);

/**
 * Loaded first by every entry point. Sets up error handling, loads the shared
 * functions, and starts the session.
 */

require_once __DIR__ . '/config.php';

// Report everything; whether it reaches the browser depends on config.
error_reporting(E_ALL);
ini_set('display_errors', config('debug') ? '1' : '0');
ini_set('log_errors', '1');

/**
 * Keep function arguments out of every stack trace.
 *
 * This must be set before anything can throw. PHP includes the arguments of
 * each frame in a trace by default, so an exception thrown anywhere below
 * authenticate_user() renders as:
 *
 *     #3 login.php(21): authenticate_user('someone', 'their real password')
 *
 * A failed database connection was therefore enough to print a user's plaintext
 * password to the browser -- and, with log_errors on, into the log file as well.
 *
 * This one ini setting covers every path that formats a trace: (string) $e,
 * getTraceAsString(), the display_errors output, and the logged copy.
 * php.ini-production enables it; the WAMP development ini does not.
 */
ini_set('zend.exception_ignore_args', '1');

date_default_timezone_set('UTC');

require_once __DIR__ . '/functions/util.php';
require_once __DIR__ . '/functions/db.php';
require_once __DIR__ . '/functions/session.php';
require_once __DIR__ . '/functions/auth.php';
require_once __DIR__ . '/functions/storage.php';
require_once __DIR__ . '/functions/layout.php';

/**
 * Turn an uncaught exception into a generic page, and log the detail.
 *
 * The original printed mysqli_error() straight to the browser, which told
 * anyone who triggered a failure the database name, the query text, and the
 * schema. Users get a neutral message; the specifics go to the error log.
 */
set_exception_handler(static function (Throwable $e): void {
    error_log('[FFS] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    if (config('debug')) {
        // Deliberately NOT (string) $e or getTraceAsString(). Both render each
        // frame's arguments, so a failure anywhere below the login form printed
        // the user's plaintext password to the browser:
        //
        //     #3 login.php(21): authenticate_user('someone', 'hunter2')
        //
        // zend.exception_ignore_args above already suppresses that, but this
        // handler does not rely on an ini setting staying correct -- it builds
        // the trace from file and line only and never touches the arguments.
        echo '<pre>';
        echo e(get_class($e) . ': ' . $e->getMessage()) . "\n\n";
        echo e('thrown at ' . $e->getFile() . ':' . $e->getLine()) . "\n\n";

        foreach ($e->getTrace() as $depth => $frame) {
            echo e(sprintf(
                "#%d %s(%s): %s%s%s()\n",
                $depth,
                $frame['file']  ?? '[internal]',
                $frame['line']  ?? '?',
                $frame['class'] ?? '',
                $frame['type']  ?? '',
                $frame['function'] ?? '?'
            ));
        }

        echo '</pre>';
        return;
    }

    // No inline style attribute here: style-src 'self' in the CSP above blocks
    // those too, so it would render unstyled anyway. Uses the stylesheet.
    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
       . '<link rel="stylesheet" href="assets/site.css">'
       . '<main class="wrap"><div class="card card--auth">'
       . '<h1>Something went wrong</h1>'
       . '<p>The request could not be completed. Please try again.</p>'
       . '<p class="mb-0"><a href="index.php">Return to sign in</a></p>'
       . '</div></main>';
});

// Baseline response headers.
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');

    // Stops a browser from second-guessing a declared content type -- the lever
    // behind a lot of "harmless upload turns out to be executable" bugs.
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');

    // No inline scripts or external origins anywhere in this project.
    header(
        "Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
        . "style-src 'self'; script-src 'self'; object-src 'none'; "
        . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
    );
}

session_boot();
