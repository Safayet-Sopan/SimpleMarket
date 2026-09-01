<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('rider');

// Shared renderer — see includes/chat_page.php
$role_css = 'rider.css';
require '../includes/chat_page.php';
