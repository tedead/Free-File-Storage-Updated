<?php
declare(strict_types=1);

/**
 * Registration: renders the form on GET, creates the account on POST.
 *
 * The original split this across register.php -> check.php -> checkuser.php ->
 * createuser.php, and reported failures by redirecting back with ?code=einvalid
 * for the form to decode. One file, and the values the user already typed
 * survive a validation error.
 */

require_once __DIR__ . '/bootstrap.php';

if (is_logged_in()) {
    redirect('files.php');
}

$errors = [];
$values = [
    'firstname' => '',
    'lastname'  => '',
    'email'     => '',
    'username'  => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();

    $values['firstname'] = trim((string) ($_POST['firstname'] ?? ''));
    $values['lastname']  = trim((string) ($_POST['lastname'] ?? ''));
    $values['email']     = trim((string) ($_POST['email'] ?? ''));
    $values['username']  = trim((string) ($_POST['username'] ?? ''));

    $password        = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    $errors = validate_registration(
        $values['email'],
        $values['username'],
        $password,
        $passwordConfirm
    );

    if ($errors === []) {
        $result = create_user(
            $values['firstname'],
            $values['lastname'],
            $values['email'],
            $values['username'],
            $password
        );

        if ($result['ok']) {
            flash('success', 'Your account is ready. Please sign in.');
            redirect('index.php');
        }

        $errors[] = $result['error'];
    }
}

// The old flow created four directories per user at registration time. Storage
// folders are now created on first upload, so an account that never uploads
// leaves nothing behind, and a failed registration cannot leave orphaned
// directories named after a user that does not exist.

page_header('Create an account');
?>

<div class="card card--auth card--wide">
    <div class="card__header">
        <h1>Create an account</h1>
        <p>Fields marked <span aria-hidden="true">*</span> are required.</p>
    </div>

    <?php render_errors($errors); ?>

    <form method="post" action="register.php">
        <?= csrf_field() ?>

        <div class="field-row">
            <div class="field">
                <label for="firstname">First name</label>
                <input type="text" id="firstname" name="firstname"
                       value="<?= e($values['firstname']) ?>" autocomplete="given-name">
            </div>

            <div class="field">
                <label for="lastname">Last name</label>
                <input type="text" id="lastname" name="lastname"
                       value="<?= e($values['lastname']) ?>" autocomplete="family-name">
            </div>
        </div>

        <div class="field">
            <label for="email">Email <span aria-hidden="true">*</span></label>
            <!-- type="email" gets the right keyboard on mobile and a free
                 client-side check; the server still validates. -->
            <input type="email" id="email" name="email"
                   value="<?= e($values['email']) ?>" autocomplete="email" required>
        </div>

        <div class="field">
            <label for="username">Username <span aria-hidden="true">*</span></label>
            <input type="text" id="username" name="username"
                   value="<?= e($values['username']) ?>" autocomplete="username" required>
            <span class="field__hint">Letters, numbers, dot, underscore and hyphen. 3-64 characters.</span>
        </div>

        <div class="field">
            <label for="password">Password <span aria-hidden="true">*</span></label>
            <input type="password" id="password" name="password"
                   autocomplete="new-password" required
                   minlength="<?= (int) config('min_password_length') ?>">
            <span class="field__hint">
                At least <?= (int) config('min_password_length') ?> characters. A passphrase of a few
                unrelated words is easier to remember and harder to guess than a short scramble.
            </span>
        </div>

        <div class="field">
            <label for="password_confirm">Confirm password <span aria-hidden="true">*</span></label>
            <input type="password" id="password_confirm" name="password_confirm"
                   autocomplete="new-password" required>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Create account</button>
            <a href="index.php" class="btn btn--ghost">Cancel</a>
        </div>
    </form>
</div>

<?php page_footer(); ?>
