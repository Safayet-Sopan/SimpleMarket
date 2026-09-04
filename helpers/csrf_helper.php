<?php
// CSRF protection for every state-changing request.
//
// How it fits together:
//   - one random token per session, minted on first use and kept until logout
//   - every POST form carries it in a hidden field, written by csrf_field()
//   - every POST request is checked in one place (the guard at the bottom of
//     this file), so a new form cannot quietly ship unprotected
//
// The session cookie is SameSite=Lax, which already blocks cross-site POSTs in
// current browsers. This is the second lock: it does not depend on the browser.

// Returns this session's token, minting one on first call.
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Prints the hidden input. Call this inside every POST form.
function csrf_field()
{
    echo '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

// True when the submitted token matches the one held in the session.
// hash_equals compares in constant time, so a wrong token leaks no timing hint.
function csrf_valid()
{
    $held = $_SESSION['csrf_token'] ?? '';
    $sent = $_POST['csrf_token'] ?? '';

    if ($held === '' || !is_string($sent) || $sent === '') {
        return false;
    }
    return hash_equals($held, $sent);
}

// Rejects the request. A page that has already promised JSON says so by
// defining CSRF_JSON_RESPONSE before including auth.php, so an AJAX caller
// gets JSON back instead of an HTML page it cannot parse.
function csrf_reject()
{
    http_response_code(403);

    if (defined('CSRF_JSON_RESPONSE')) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid or expired security token']);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<title>Request blocked</title>'
        . '<link rel="stylesheet" href="' . (defined('BASE_URL') ? BASE_URL : '/SimpleMarket/')
        . 'assets/css/style.css"></head><body class="page">'
        . '<div class="form-card"><h1>Request blocked</h1>'
        . '<p class="error">Your security token was missing or has expired.</p>'
        . '<p class="notice">This usually means the page sat open too long, or the '
        . 'form was submitted from somewhere other than SimpleMarket. Go back, '
        . 'reload the page and try again.</p></div></body></html>';
    exit;
}

// The guard. Runs on include, so every page that requires auth.php is covered
// whether or not its author remembered CSRF.
// REQUEST_METHOD is absent under CLI (the test scripts run setup.php that way),
// so default it rather than reading a key that may not be there.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !csrf_valid()) {
    csrf_reject();
}
