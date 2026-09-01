<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('rider');

// Shared renderer — see includes/notifications_page.php
$role_css = 'rider.css';
require '../includes/notifications_page.php';
