<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('customer');

// Shared renderer — see includes/chat_page.php
$role_css = 'customer.css';
require '../includes/chat_page.php';
