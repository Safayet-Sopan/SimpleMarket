<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('admin');

// Shared renderer — see includes/notifications_page.php
$role_css = 'admin.css';
require '../includes/notifications_page.php';
