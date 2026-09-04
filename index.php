<?php
// Front controller. Every request in the whole app enters here:
//
//     index.php?page=<where>&action=<what>
//
// Nothing else in the project is a web entry point. The role directories that
// used to hold one PHP file per page are gone; their logic lives in
// controllers/, their SQL in models/, and their markup in views/.
//
// Load order matters: config defines the constants the helpers read, the
// database connection has to exist before a model runs, and auth_helper starts
// the session (and pulls in the CSRF guard) before any controller sees a POST.

// ------------------------------------------------------------------ routing --
$page   = $_GET['page']   ?? 'home';
$action = $_GET['action'] ?? 'index';

// The navbar reads this to work out which link is the current page.
$GLOBALS['route_action'] = $action;

// An AJAX route has already promised JSON, so a rejected CSRF check must answer
// in JSON too. csrf_helper.php reads this constant, so it is set before the
// helper is loaded.
if ($page === 'ajax') {
    define('CSRF_JSON_RESPONSE', true);
}

// ------------------------------------------------------------------ loading --
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

require_once __DIR__ . '/helpers/view_helper.php';
require_once __DIR__ . '/helpers/validation_helper.php';
require_once __DIR__ . '/helpers/functions_helper.php';
require_once __DIR__ . '/helpers/auth_helper.php';   // starts the session + CSRF guard

foreach ([
    'notification_model', 'user_model', 'product_model', 'order_model',
    'offer_model', 'review_model', 'message_model', 'earning_model',
    'note_model', 'report_model',
] as $model) {
    require_once __DIR__ . '/models/' . $model . '.php';
}

foreach ([
    'auth_controller', 'account_controller', 'admin_controller', 'seller_controller',
    'customer_controller', 'rider_controller', 'ajax_controller',
] as $controller) {
    require_once __DIR__ . '/controllers/' . $controller . '.php';
}

// ----------------------------------------------------------------- dispatch --
// Each protected branch calls require_role() first, so a signed-out or
// wrong-role visitor never reaches a controller.
switch ($page) {

    case 'home':
        auth_home();
        break;

    case 'login':
        auth_login();
        break;

    case 'register':
        auth_register();
        break;

    case 'logout':
        auth_logout();
        break;

    case 'admin':
        require_role('admin');
        admin_dispatch($action);
        break;

    case 'seller':
        require_role('seller');
        seller_dispatch($action);
        break;

    case 'customer':
        require_role('customer');
        customer_dispatch($action);
        break;

    case 'rider':
        require_role('rider');
        rider_dispatch($action);
        break;

    case 'ajax':
        ajax_dispatch($action);
        break;

    default:
        // An unknown page is not an error worth a stack trace — send them home.
        redirect_to('home');
}
