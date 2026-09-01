<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('seller');

// Shared renderer — see includes/notifications_page.php
$role_css = 'seller.css';
require '../includes/notifications_page.php';
