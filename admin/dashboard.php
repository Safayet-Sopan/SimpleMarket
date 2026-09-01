<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('admin');

// Pending seller approvals
$pending_sellers = 0;
/** @var mysqli $conn */
$result = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM seller_profiles WHERE approval_status = 'pending'");
if ($row = mysqli_fetch_assoc($result)) {
    $pending_sellers = $row['cnt'];
}

// Total active sellers
$active_sellers = 0;
$result = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM seller_profiles WHERE approval_status = 'approved'");
if ($row = mysqli_fetch_assoc($result)) {
    $active_sellers = $row['cnt'];
}

// Total orders and revenue
$total_orders = 0;
$total_revenue = 0;
$result = mysqli_query($conn, "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS revenue FROM orders WHERE status = 'delivered'");
if ($row = mysqli_fetch_assoc($result)) {
    $total_orders = $row['cnt'];
    $total_revenue = $row['revenue'];
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
    <title>Admin Dashboard — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body>
    <h1>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></h1>

    <div class="stats">
        <div class="stat-card">
            <p>Pending Seller Approvals</p>
            <h2><?php echo $pending_sellers; ?></h2>
            <a href="seller_approvals.php">Review</a>
        </div>
        <div class="stat-card">
            <p>Active Sellers</p>
            <h2><?php echo $active_sellers; ?></h2>
        </div>
        <div class="stat-card">
            <p>Delivered Orders</p>
            <h2><?php echo $total_orders; ?></h2>
        </div>
        <div class="stat-card">
            <p>Total Revenue</p>
            <h2>৳<?php echo number_format($total_revenue, 2); ?></h2>
            <a href="sales_overview.php">View Breakdown</a>
        </div>
    </div>

    <a href="commission_calculator.php">Commission Calculator</a>
    <a href="sales_overview.php">Sales Overview</a>
    <a href="notifications.php" id="notifications-link">Notifications<?php echo $unread_count > 0 ? " (" . $unread_count . ")" : ""; ?></a>
    <a href="../logout.php">Logout</a>

    <script src="../assets/js/main.js"></script>
</body>

</html>