<?php
declare(strict_types=1);

/**
 * Shared page chrome.
 *
 * Each page used to carry its own copy of a bare <html> with no doctype, no
 * charset, no viewport, and a layout built from nested <table> elements with
 * bgcolor attributes. One header and footer instead.
 */

function page_header(string $title, string $bodyClass = ''): void
{
    $user = current_user_name();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <!-- Absent before, so phones rendered the desktop layout scaled down. -->
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> &middot; Free File Storage</title>
    <link rel="stylesheet" href="assets/site.css">
    <script src="assets/site.js" defer></script>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><text y='14' font-size='14'>&#128193;</text></svg>">
</head>
<body class="<?= e($bodyClass) ?>">

<a class="skip-link" href="#main">Skip to content</a>

<header class="site-header">
    <div class="wrap site-header__inner">
        <a class="brand" href="<?= is_logged_in() ? 'files.php' : 'index.php' ?>">
            <span class="brand__mark" aria-hidden="true">&#9635;</span>
            <span class="brand__text">Free File Storage</span>
        </a>

        <?php if ($user !== null): ?>
            <nav class="site-nav">
                <span class="site-nav__user">Signed in as <strong><?= e($user) ?></strong></span>
                <form method="post" action="logout.php" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--ghost btn--sm">Sign out</button>
                </form>
            </nav>
        <?php endif; ?>
    </div>
</header>

<main id="main" class="wrap">
    <?php render_flashes(); ?>
    <?php
}

function page_footer(): void
{
    ?>
</main>

<footer class="site-footer">
    <div class="wrap">
        <p>Free File Storage &mdash; personal project, modernised <?= date('Y') ?>.</p>
    </div>
</footer>

</body>
</html>
    <?php
}

/**
 * Render and clear queued flash messages.
 */
function render_flashes(): void
{
    $flashes = take_flashes();

    if ($flashes === []) {
        return;
    }

    // role="status" so a screen reader announces these without stealing focus.
    echo '<div class="flashes" role="status" aria-live="polite">';

    foreach ($flashes as $flash) {
        $type = in_array($flash['type'], ['success', 'error', 'info'], true)
            ? $flash['type']
            : 'info';

        $icon = match ($type) {
            'success' => '&#10003;',
            'error'   => '&#33;',
            default   => '&#105;',
        };

        // Successes and notices disappear on their own; errors do not.
        //
        // A success is a receipt for something the user just did and already
        // knows about -- it has served its purpose the moment it is read. An
        // error is the opposite: it is often the only explanation of why the
        // thing they wanted did not happen, and it may be long enough that a
        // timer runs out mid-sentence. Auto-dismissing those means a user who
        // glanced away is left with no idea what went wrong.
        //
        // Both kinds are closable by hand.
        $dismissAfter = $type === 'error' ? 0 : (int) config('flash_dismiss_ms');

        printf(
            '<div class="flash flash--%s" data-dismiss-after="%d">'
                . '<span class="flash__icon" aria-hidden="true">%s</span>'
                . '<span class="flash__text">%s</span>'
                . '<button type="button" class="flash__close" aria-label="Dismiss this message">'
                . '<span aria-hidden="true">&times;</span>'
                . '</button>'
                . '</div>',
            e($type),
            $dismissAfter,
            $icon,
            e($flash['message'])
        );
    }

    echo '</div>';
}

/**
 * Render a list of validation errors above a form.
 *
 * @param array<int, string> $errors
 */
function render_errors(array $errors): void
{
    if ($errors === []) {
        return;
    }

    echo '<div class="flash flash--error flash--list"><ul>';
    foreach ($errors as $error) {
        echo '<li>' . e($error) . '</li>';
    }
    echo '</ul></div>';
}

/**
 * A small SVG glyph for a file category.
 */
function category_icon(string $category): string
{
    return match ($category) {
        'Application' => '&#9881;',
        'Utility'     => '&#128295;',
        'Compressed'  => '&#128230;',
        default       => '&#128196;',
    };
}
