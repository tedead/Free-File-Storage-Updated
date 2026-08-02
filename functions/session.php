<?php
declare(strict_types=1);

/**
 * Session handling, flash messages, CSRF, and the login guard.
 */

/**
 * Start the session with hardened cookie settings.
 *
 * The original called session_start() with PHP's defaults, and called it after
 * emitting output in several places.
 */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,          // dies with the browser session
        'path'     => '/',
        'httponly' => true,       // not readable from JavaScript
        'secure'   => config('secure_cookies'),
        'samesite' => 'Lax',      // blocks the cookie on cross-site POSTs
    ]);

    // Do not accept a session id supplied in the URL.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_name('FFSSESSID');
    session_start();

    session_enforce_timeouts();
}

/**
 * Expire sessions that are too old, or idle too long.
 */
function session_enforce_timeouts(): void
{
    $now = time();

    $started = $_SESSION['started_at'] ?? null;
    $seen    = $_SESSION['last_seen_at'] ?? null;

    if ($started !== null) {
        $tooOld  = ($now - (int) $started) > config('session_absolute_seconds');
        $tooIdle = $seen !== null && ($now - (int) $seen) > config('session_idle_seconds');

        if ($tooOld || $tooIdle) {
            session_logout();
            session_boot();
            flash('info', 'Your session expired. Please sign in again.');
        }
    }

    $_SESSION['last_seen_at'] = $now;
}

/**
 * Record a successful login.
 */
function session_login(string $userId, string $userName, string $displayName): void
{
    // Regenerate the id so a session fixed by an attacker before login (via a
    // planted cookie) is not the one that ends up authenticated. The original
    // kept whatever id the browser arrived with.
    session_regenerate_id(true);

    $_SESSION['user_id']      = $userId;
    $_SESSION['user_name']    = $userName;
    $_SESSION['display_name'] = $displayName;
    $_SESSION['started_at']   = time();
    $_SESSION['last_seen_at'] = time();
}

/**
 * Clear the session and its cookie.
 */
function session_logout(): void
{
    $_SESSION = [];

    // session_destroy() alone leaves the cookie in the browser.
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

function current_user_id(): ?string
{
    return $_SESSION['user_id'] ?? null;
}

function current_user_name(): ?string
{
    return $_SESSION['display_name'] ?? $_SESSION['user_name'] ?? null;
}

function is_logged_in(): bool
{
    return current_user_id() !== null;
}

/**
 * Guard for pages that require a signed-in user.
 */
function require_login(): string
{
    $userId = current_user_id();

    if ($userId === null) {
        flash('error', 'Please sign in to continue.');
        redirect('index.php');   // redirect() calls exit
    }

    return $userId;
}

/**
 * Guard for handlers that only accept POST.
 *
 * delete.php and submit.php changed state; nothing stopped them being triggered
 * by a GET, which meant an <img src="delete.php?..."> could act on a visitor.
 */
function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method Not Allowed');
    }
}

// -----------------------------------------------------------------------------
// CSRF
// -----------------------------------------------------------------------------

/**
 * The session's CSRF token, created on first use.
 *
 * There was no CSRF protection at all before. Any page on the internet could
 * POST to delete.php on behalf of a logged-in visitor and destroy their files.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * A hidden input carrying the token, for embedding in every form.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Verify the submitted token, or stop the request.
 */
function csrf_verify(): void
{
    $submitted = $_POST['csrf_token'] ?? '';

    // hash_equals, not ==, so the comparison cannot be timed.
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        // 403, not 419.
        //
        // 419 is a Laravel convention, not a registered HTTP status code. PHP
        // sets it happily, but Apache maps unrecognised codes to 500 on the way
        // out -- so a correctly rejected request looked like a server crash to
        // the client, to monitoring, and to anyone reading the logs. The PHP
        // built-in server passes 419 through untouched, which is why this only
        // appeared once the app ran under Apache.
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Your session has expired or the request could not be verified. Please go back and try again.');
    }
}

// -----------------------------------------------------------------------------
// Flash messages
// -----------------------------------------------------------------------------

/**
 * Queue a message for the next page render.
 *
 * Replaces the "?fail=nouser" / "?code=pwdshort" query-string scheme, which put
 * application state in a URL the user could edit, bookmark, or be linked to.
 *
 * @param string $type 'success' | 'error' | 'info'
 */
function flash(string $type, string $message): void
{
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

/**
 * Read and clear queued messages.
 *
 * @return array<int, array{type: string, message: string}>
 */
function take_flashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);

    return $flashes;
}
