<?php
// The role-coloured top bar, drawn by the header partial on every signed-in page.
//
// Links are built with url(), so they are absolute and work from any route.
// The active link is matched on the current action, which the front controller
// puts in $GLOBALS['route_action'].
//
// (Never paste a literal closing PHP tag into a comment here — a "?" followed
// by ">" ends PHP mode even inside a // comment and dumps the file as text.)

if (!isset($_SESSION['user_id'])) {
    // Signed-out pages (login, register) have no bar.
    return;
}

$nav_role = $_SESSION['role'] ?? '';

// label => action, in the order the design lists them.
$nav_items_by_role = [
    'admin' => [
        'Dashboard'     => 'dashboard',
        'Users'         => 'users',
        'Approvals'     => 'approvals',
        'Commission'    => 'commission',
        'Sales'         => 'sales',
        'Search'        => 'search',
        'Notifications' => 'notifications',
        'Profile'       => 'profile',
    ],
    'seller' => [
        'Dashboard'     => 'dashboard',
        'Products'      => 'products',
        'Low stock'     => 'low_stock',
        'Orders'        => 'orders',
        'Offers'        => 'bidding',
        'Search'        => 'search',
        'Notifications' => 'notifications',
        'Profile'       => 'profile',
    ],
    'customer' => [
        'Dashboard'     => 'dashboard',
        'Browse'        => 'search',
        'Orders'        => 'orders',
        'Notifications' => 'notifications',
        'Profile'       => 'profile',
    ],
    'rider' => [
        'Dashboard'     => 'dashboard',
        'Deliveries'    => 'deliveries',
        'Notes'         => 'notes',
        'Earnings'      => 'earnings',
        'Chat'          => 'chat',
        'Search'        => 'search',
        'Notifications' => 'notifications',
        'Profile'       => 'profile',
    ],
];

if (!isset($nav_items_by_role[$nav_role])) {
    return;
}
$nav_items = $nav_items_by_role[$nav_role];

// Which link is the current page. $nav_active set by the view wins; otherwise
// match the running action against the list above.
if (!isset($nav_active)) {
    $nav_active = '';
    $nav_current = $GLOBALS['route_action'] ?? '';
    foreach ($nav_items as $nav_label => $nav_action) {
        if ($nav_action === $nav_current) {
            $nav_active = $nav_label;
            break;
        }
    }
}

// Unread count for the Notifications link. Computed once here so every page
// shows the same number without repeating the query.
$nav_unread = notification_unread_count($GLOBALS['conn'], $_SESSION['user_id']);
?>
<header class="site-header role-<?php echo e($nav_role); ?>">
    <a class="brand" href="<?php echo url($nav_role, 'dashboard'); ?>">SimpleMarket</a>
    <span class="role-word"><?php echo e($nav_role); ?></span>

    <nav class="site-nav">
        <?php foreach ($nav_items as $nav_label => $nav_action): ?>
            <?php
            $nav_is_active = ($nav_label === $nav_active);
            // main.js finds this link by id and keeps the count current.
            $nav_id = ($nav_label === 'Notifications') ? ' id="notifications-link"' : '';
            $nav_text = $nav_label;
            if ($nav_label === 'Notifications' && $nav_unread > 0) {
                $nav_text .= ' (' . $nav_unread . ')';
            }
            ?>
            <a class="nav-link<?php echo $nav_is_active ? ' nav-link-active' : ''; ?>"
               href="<?php echo url($nav_role, $nav_action); ?>"<?php echo $nav_id; ?>><?php
                echo e($nav_text);
            ?></a>
        <?php endforeach; ?>
        <a class="nav-link" href="<?php echo url('logout'); ?>">Log out</a>
    </nav>
</header>
