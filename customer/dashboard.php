<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('customer');

$customer_id = $_SESSION['user_id'];

// Active orders
$active_orders = 0;
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM orders WHERE customer_id = ? AND status NOT IN ('delivered','cancelled')");
mysqli_stmt_bind_param($stmt, 'i', $customer_id);
mysqli_stmt_execute($stmt);
$r = mysqli_stmt_get_result($stmt);
$active_orders = mysqli_fetch_assoc($r)['cnt'];
mysqli_stmt_close($stmt);

// Orders awaiting feedback (delivered, no review yet)
$stmt = mysqli_prepare($conn,
    "SELECT COUNT(*) AS cnt FROM orders o
     WHERE o.customer_id = ? AND o.status = 'delivered'
     AND o.order_id NOT IN (SELECT order_id FROM reviews WHERE customer_id = ?)"
);
mysqli_stmt_bind_param($stmt, 'ii', $customer_id, $customer_id);
mysqli_stmt_execute($stmt);
$r = mysqli_stmt_get_result($stmt);
$awaiting_feedback = mysqli_fetch_assoc($r)['cnt'];
mysqli_stmt_close($stmt);

// Bids needing the customer's attention: a seller countered, or accepted and
// the customer has not placed the order yet.
$stmt = mysqli_prepare($conn,
    "SELECT COUNT(*) AS cnt FROM offers
     WHERE customer_id = ? AND status IN ('countered','accepted') AND converted_order_id IS NULL"
);
mysqli_stmt_bind_param($stmt, 'i', $customer_id);
mysqli_stmt_execute($stmt);
$r = mysqli_stmt_get_result($stmt);
$open_bids = mysqli_fetch_assoc($r)['cnt'];
mysqli_stmt_close($stmt);
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
    <title>Customer Dashboard — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/customer.css">
</head>
<body>
    <h1>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></h1>

    <div class="stats">
        <div class="stat-card">
            <p>Active Orders</p>
            <h2><?php echo $active_orders; ?></h2>
            <a href="order_tracking.php">Track</a>
        </div>
        <div class="stat-card <?php echo $open_bids > 0 ? 'alert' : ''; ?>">
            <p>Bids Needing You</p>
            <h2><?php echo $open_bids; ?></h2>
            <a href="make_offer.php">Review Bids</a>
        </div>
        <div class="stat-card">
            <p>Awaiting Feedback</p>
            <h2><?php echo $awaiting_feedback; ?></h2>
            <a href="product_feedback.php">Leave Review</a>
        </div>
    </div>

    <a href="search.php">Browse Products</a>
    <a href="orders.php">My Orders</a>
    <a href="chat.php">Order Chat</a>
    <a href="make_offer.php">My Bids</a>
    <a href="notifications.php" id="notifications-link">Notifications<?php echo $unread_count > 0 ? " (" . $unread_count . ")" : ""; ?></a>
    <a href="../logout.php">Logout</a>

    <script src="../assets/js/main.js"></script>
</body>
</html>