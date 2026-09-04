<?php
// Small shared utilities that are not validation, auth or view concerns.

function flash_set($message, $type = 'info')
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function flash_get()
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function is_logged_in()
{
    return isset($_SESSION['user_id']);
}
