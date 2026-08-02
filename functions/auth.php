<?php
declare(strict_types=1);

/**
 * Authentication and registration.
 *
 * All database work is delegated to the ffs.* stored procedures.
 */

/**
 * Verify a username and password.
 *
 * The original built "SELECT * FROM users WHERE UserName = '$username' AND
 * Password = '$password'" by interpolation and counted the rows. Two problems:
 *
 *   1. Injection. A password of  ' OR '1'='1  made the WHERE clause true and
 *      logged the visitor in as the first matching user, no password needed.
 *   2. It required the password to be stored in a form the database could
 *      compare against -- i.e. plaintext. A dump of the users table was a list
 *      of working credentials, and because people reuse passwords, a list of
 *      working credentials for other sites too.
 *
 * Now: look the user up by name only, and let password_verify() compare the
 * candidate against a bcrypt hash in PHP.
 *
 * @return array{user_id: string, user_name: string, display_name: string}|null
 */
/**
 * The hashing algorithm and options, in one place.
 *
 * Both password_hash() and password_needs_rehash() must be given identical
 * arguments or they disagree: if the cost passed to needs_rehash is lower than
 * the one used to hash, every login decides the stored hash is fine and no user
 * ever migrates; if it is higher, every login re-hashes forever. Returning them
 * from a single function makes drifting apart impossible.
 *
 * PASSWORD_DEFAULT rather than PASSWORD_BCRYPT: if a future PHP moves the
 * default to argon2id, needs_rehash() notices the algorithm changed and
 * migrates users automatically. The 'cost' option is simply ignored by argon2,
 * so nothing breaks in the meantime.
 *
 * @return array{0: string|int|null, 1: array<string, int>}
 */
function password_options(): array
{
    return [PASSWORD_DEFAULT, ['cost' => config('bcrypt_cost')]];
}

function authenticate_user(string $username, string $password): ?array
{
    $row = db_row('GetUserForLogin', [$username]);

    if ($row === null) {
        // Hash anyway. Returning early for an unknown username makes the
        // response measurably faster than for a known one, which lets an
        // attacker enumerate valid accounts by timing alone.
        password_verify($password, '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30M1p1DkVJPS');
        return null;
    }

    if (!password_verify($password, $row['PasswordHash'])) {
        return null;
    }

    // Upgrade the stored hash if the configured cost -- or PHP's default
    // algorithm -- has moved on since it was written. This is the only moment
    // the plaintext is available to re-hash with, so it has to happen here.
    // Costs nothing on the normal path.
    [$algo, $options] = password_options();

    if (password_needs_rehash($row['PasswordHash'], $algo, $options)) {
        db_exec('UpdatePasswordHash', [
            $row['UserID'],
            password_hash($password, $algo, $options),
        ]);
    }

    db_exec('TouchLastLogin', [$row['UserID']]);

    return [
        'user_id'      => (string) $row['UserID'],
        'user_name'    => (string) $row['UserName'],
        'display_name' => (string) $row['DisplayName'],
    ];
}

/**
 * Create a user.
 *
 * @return array{ok: bool, error: string|null}
 */
function create_user(
    string $firstName,
    string $lastName,
    string $email,
    string $username,
    string $password
): array {
    $status = null;

    db_call(
        'CreateUser',
        [
            uuid4(),
            $firstName,
            $lastName,
            $email,
            $username,              // DisplayName defaults to the username
            $username,
            // Same algorithm and cost as the rehash check in
            // authenticate_user(), because both read password_options().
            password_hash($password, ...password_options()),
        ],
        // The @Status OUTPUT parameter sits at position 7 (0-based).
        [7 => ['type' => PDO::PARAM_STR, 'length' => 16, 'init' => '']],
        $out
    );

    $status = $out[7] ?? 'ok';

    return match ($status) {
        'ok'             => ['ok' => true,  'error' => null],
        'username_taken' => ['ok' => false, 'error' => 'That username is already taken.'],
        'email_taken'    => ['ok' => false, 'error' => 'An account already exists for that email address.'],
        default          => ['ok' => false, 'error' => 'The account could not be created. Please try again.'],
    };
}

/**
 * Validate registration input.
 *
 * @return array<int, string> Error messages; empty means valid.
 */
function validate_registration(
    string $email,
    string $username,
    string $password,
    string $passwordConfirm
): array {
    $errors = [];

    if ($email === '' || $username === '' || $password === '') {
        $errors[] = 'Email, username and password are all required.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'That email address does not look valid.';
    }

    if ($username !== '' && !preg_match('/\A[A-Za-z0-9._-]{3,64}\z/', $username)) {
        $errors[] = 'Usernames may use letters, numbers, dot, underscore and hyphen, and must be 3-64 characters.';
    }

    $minimum = config('min_password_length');
    if ($password !== '' && mb_strlen($password) < $minimum) {
        $errors[] = "Passwords must be at least {$minimum} characters.";
    }

    // Length is capped because bcrypt silently ignores anything past 72 bytes:
    // without this, two different long passwords sharing a prefix both work.
    if (strlen($password) > 72) {
        $errors[] = 'Passwords must be 72 bytes or fewer.';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'The two password fields do not match.';
    }

    return $errors;
}
