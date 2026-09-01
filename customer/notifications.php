<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('customer');

// Shared renderer — see includes/notifications_page.php
$role_css = 'customer.css';
require '../includes/notifications_page.php';
