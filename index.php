<?php
declare(strict_types=1);

/**
 * Sign in.
 */

require_once __DIR__ . '/bootstrap.php';

// Already signed in? Nothing to do here.
if (is_logged_in()) {
    redirect('files.php');
}

// Preserved across a failed attempt so the user does not retype it. The
// password is never echoed back.
$username = $_SESSION['old_username'] ?? '';
unset($_SESSION['old_username']);

page_header('Sign in');
?>

<div class="card card--auth">
    <div class="card__header">
        <h1>Sign in</h1>
        <p>Access your stored files.</p>
    </div>

    <form method="post" action="login.php">
        <?= csrf_field() ?>

        <div class="field">
            <label for="username">Username</label>
            <input type="text" id="username" name="username"
                   value="<?= e($username) ?>"
                   autocomplete="username" required autofocus>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password"
                   autocomplete="current-password" required>
        </div>

        <button type="submit" class="btn btn--block">Sign in</button>
    </form>

    <p class="text-center small mt-1 mb-0 text-muted">
        No account yet? <a href="register.php">Create one</a>
    </p>
</div>

<?php page_footer(); ?>
