<?php
declare(strict_types=1);

/**
 * Sign out.
 *
 * POST only, with a CSRF token. As a GET link, any page could sign a visitor
 * out with an <img src="logout.php"> -- harmless compared to a forced delete,
 * but it is the same hole and costs nothing to close.
 */

require_once __DIR__ . '/bootstrap.php';

require_post();
csrf_verify();

session_logout();

// A fresh session purely to carry the confirmation message.
session_boot();
flash('success', 'You have been signed out.');

redirect('index.php');
