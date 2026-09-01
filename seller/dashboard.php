<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('seller');

// Get this seller's seller_id
$seller_id = null;
$stmt = mysqli_prepare($conn, "SELECT seller_id, shop_name, approval_status FROM seller_profiles WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$seller = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if ($seller) {
    $seller_id = $seller['seller_id'];
}

// Product count + low stock count
$product_count = 0;
$low_stock_count = 0;
if ($seller_id) {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM products WHERE seller_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $seller_id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $product_count = mysqli_fetch_assoc($r)['cnt'];
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM products WHERE seller_id = ? AND stock_quantity <= low_stock_threshold");
    mysqli_stmt_bind_param($stmt, 'i', $seller_id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $low_stock_count = mysqli_fetch_assoc($r)['cnt'];
    mysqli_stmt_close($stmt);
}

// Pending orders
$pending_orders = 0;
if ($seller_id) {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM orders WHERE seller_id = ? AND status NOT IN ('delivered','cancelled')");
    mysqli_stmt_bind_param($stmt, 'i', $seller_id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $pending_orders = mysqli_fetch_assoc($r)['cnt'];
    mysqli_stmt_close($stmt);
}
// Unread notifications, refreshed live by assets/js/main.js
$unread_count = 0;
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0");
mysqli_stmt_bind_param($stmt, 'i', $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$unread_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['cnt'];
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Seller Dashboard — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/seller.css">
</head>
<body>
    <?php if ($seller && $seller['approval_status'] === 'pending'): ?>
        <p class="notice">Your shop application is still pending admin approval.</p>
    <?php endif; ?>

    <h1>Welcome, <?php echo htmlspecialchars($seller['shop_name'] ?? $_SESSION['full_name']); ?></h1>

    <div class="stats">
        <div class="stat-card">
            <p>Products Listed</p>
            <h2><?php echo $product_count; ?></h2>
            <a href="products.php">Manage</a>
        </div>
        <div class="stat-card <?php echo $low_stock_count > 0 ? 'alert' : ''; ?>">
            <p>Low Stock Items</p>
            <h2><?php echo $low_stock_count; ?></h2>
            <a href="low_stock_alert.php">View</a>
        </div>
        <div class="stat-card">
            <p>Pending Orders</p>
            <h2><?php echo $pending_orders; ?></h2>
            <a href="orders.php">View</a>
        </div>
    </div>

    <a href="price_bidding.php">Price Offers</a>
    <a href="orders.php">Orders</a>
    <a href="payment_methods.php">Payment Methods</a>
    <a href="chat.php">Order Chat</a>
    <a href="notifications.php" id="notifications-link">Notifications<?php echo $unread_count > 0 ? " (" . $unread_count . ")" : ""; ?></a>
    <a href="../logout.php">Logout</a>

    <script src="../assets/js/main.js"></script>
</body>
</html>