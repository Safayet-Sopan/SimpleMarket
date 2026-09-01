<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Clears the session and deletes this device's remember token — see auth.php
logout_user($conn);

header('Location: login.php');
exit;
