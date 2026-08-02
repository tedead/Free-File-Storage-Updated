<?php
declare(strict_types=1);

/**
 * Sign-in handler.
 */

require_once __DIR__ . '/bootstrap.php';

require_post();
csrf_verify();

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

// Note what is *not* happening: no htmlspecialchars() on the way in. The old
// login.php ran both values through it before the lookup, so a password
// containing " or & was silently altered and never matched what registration
// had stored. Escaping belongs at output, not input.

$user = authenticate_user($username, $password);

if ($user === null) {
    // One message for both "no such user" and "wrong password". Distinguishing
    // them tells an attacker which usernames are real.
    flash('error', 'Those credentials did not match an account.');
    $_SESSION['old_username'] = $username;
    redirect('index.php');
}

session_login($user['user_id'], $user['user_name'], $user['display_name']);

flash('success', 'Welcome back, ' . $user['display_name'] . '.');
redirect('files.php');
