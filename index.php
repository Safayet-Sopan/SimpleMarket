<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Send logged-in users straight to their role dashboard; everyone else logs in
// first. A valid remember cookie counts as logged in.
try_remember_login($conn);
$role = current_role();

if ($role !== null && in_array($role, ['admin', 'seller', 'customer', 'rider'], true)) {
    header('Location: ' . $role . '/dashboard.php');
    exit;
}

header('Location: login.php');
exit;
