<?php
// Session + "remember me" cookie handling.
//
// How it fits together:
//   - the session cookie is what actually logs you in, and dies with the browser
//   - the remember cookie is optional and long-lived; when a session is gone but
//     the remember cookie is still valid, the session is rebuilt from it
//   - the cookie carries a random token; only its SHA-256 hash is in the database

define('REMEMBER_COOKIE', 'simplemarket_remember');
define('REMEMBER_DAYS', 30);

// Log a user out after this long with no activity.
define('SESSION_IDLE_SECONDS', 1800); // 30 minutes

if (session_status() === PHP_SESSION_NONE) {
    // httponly keeps JavaScript away from the session id; samesite blunts CSRF.
    // 'secure' stays false because local XAMPP is plain HTTP — turn it on if
    // this is ever served over HTTPS.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => false,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Drop a session that has been idle too long, before anything trusts it
if (isset($_SESSION['user_id'], $_SESSION['last_activity'])
    && (time() - $_SESSION['last_activity']) > SESSION_IDLE_SECONDS) {
    $_SESSION = [];
    session_destroy();
    session_start();
}
if (isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
}

// Puts a verified user into the session. Call this instead of writing
// $_SESSION by hand, so session id regeneration is never forgotten.
function login_user($conn, $user, $remember = false)
{
    // A fresh session id on login is what stops session fixation: any id an
    // attacker planted before login stops being the logged-in session.
    session_regenerate_id(true);

    $_SESSION['user_id']       = $user['user_id'];
    $_SESSION['full_name']     = $user['full_name'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['last_activity'] = time();

    if ($remember) {
        issue_remember_cookie($conn, $user['user_id']);
    }
}

function issue_remember_cookie($conn, $user_id)
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = time() + (REMEMBER_DAYS * 24 * 60 * 60);
    $expires_sql = date('Y-m-d H:i:s', $expires);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, 'iss', $user_id, $hash, $expires_sql);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // Only the raw token goes to the browser. The database never sees it.
    setcookie(REMEMBER_COOKIE, $token, [
        'expires'  => $expires,
        'path'     => '/',
        'httponly' => true,
        'secure'   => false,
        'samesite' => 'Lax',
    ]);
}

// Rebuilds a session from the remember cookie. Safe to call on every request:
// it does nothing when already logged in or when there is no cookie.
function try_remember_login($conn)
{
    if (isset($_SESSION['user_id'])) {
        return false;
    }
    if (empty($_COOKIE[REMEMBER_COOKIE])) {
        return false;
    }

    $hash = hash('sha256', $_COOKIE[REMEMBER_COOKIE]);

    // The token must be unexpired AND the account still usable — a suspended
    // user must not be able to walk back in on an old cookie.
    $stmt = mysqli_prepare(
        $conn,
        "SELECT u.user_id, u.full_name, u.role
         FROM remember_tokens rt
         JOIN users u ON u.user_id = rt.user_id
         WHERE rt.token_hash = ? AND rt.expires_at > NOW() AND u.status = 'active'"
    );
    mysqli_stmt_bind_param($stmt, 's', $hash);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$user) {
        // Unknown, expired or revoked — clear the dead cookie
        clear_remember_cookie();
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id']       = $user['user_id'];
    $_SESSION['full_name']     = $user['full_name'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['last_activity'] = time();
    return true;
}

function clear_remember_cookie()
{
    if (isset($_COOKIE[REMEMBER_COOKIE])) {
        unset($_COOKIE[REMEMBER_COOKIE]);
    }
    setcookie(REMEMBER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Full logout: session gone, this device's remember token deleted.
function logout_user($conn)
{
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        $hash = hash('sha256', $_COOKIE[REMEMBER_COOKIE]);
        $stmt = mysqli_prepare($conn, "DELETE FROM remember_tokens WHERE token_hash = ?");
        mysqli_stmt_bind_param($stmt, 's', $hash);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    clear_remember_cookie();

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function require_login() {
    // Give the remember cookie a chance before turning anyone away
    if (!isset($_SESSION['user_id']) && isset($GLOBALS['conn'])) {
        try_remember_login($GLOBALS['conn']);
    }
    if (!isset($_SESSION['user_id'])) {
        header('Location: /SimpleMarket/login.php');
        exit;
    }
}

function require_role($role) {
    require_login();
    if ($_SESSION['role'] !== $role) {
        header('Location: /SimpleMarket/login.php');
        exit;
    }
}

function current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function current_role() {
    return $_SESSION['role'] ?? null;
}
