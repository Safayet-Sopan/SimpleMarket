<?php
// URL building and view rendering.
//
// Every link in the app goes through url(), so the routing scheme lives in one
// function. Every page is drawn by render(), which wraps a view in the shared
// header/footer partials.

// url('seller', 'products')            -> index.php?page=seller&action=products
// url('seller', 'products', ['edit'=>3]) -> ...&edit=3
// url('login')                         -> index.php?page=login
function url($page, $action = '', $params = [])
{
    $query = 'page=' . urlencode($page);
    if ($action !== '') {
        $query .= '&action=' . urlencode($action);
    }
    foreach ($params as $key => $value) {
        $query .= '&' . urlencode($key) . '=' . urlencode($value);
    }
    return BASE_URL . 'index.php?' . $query;
}

// Shorthand for the escaping that every echo of user data needs.
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Draws one view inside the shared chrome.
//
// $view is a path under views/, without the extension: 'seller/products'.
// $data becomes local variables in the view, so a view never touches $_POST,
// $_GET or the database — the controller hands it everything it needs.
function render($view, $data = [])
{
    extract($data, EXTR_SKIP);

    $view_file = __DIR__ . '/../views/' . $view . '.php';
    if (!is_file($view_file)) {
        http_response_code(500);
        die('View not found: ' . e($view));
    }

    // $page_title and $role_css are read by the header partial. A view that
    // does not set them gets sensible defaults.
    if (!isset($page_title)) { $page_title = SITE_NAME; }
    if (!isset($role_css))   { $role_css = current_role() ?: ''; }
    if (!isset($body_class)) { $body_class = ''; }
    // A view can opt out of the site navbar by passing bare => true.
    if (!isset($bare))       { $bare = false; }

    require __DIR__ . '/../views/partials/header.php';
    require $view_file;
    require __DIR__ . '/../views/partials/footer.php';
}

// Renders a JSON response and stops. Used by the ajax controller.
function json_response($payload, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function redirect_to($page, $action = '', $params = [])
{
    header('Location: ' . url($page, $action, $params));
    exit;
}
