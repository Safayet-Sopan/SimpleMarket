<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('rider');

// Get this rider's rider_id
$rider_id = null;
$stmt = mysqli_prepare($conn, "SELECT rider_id, vehicle_type, availability_status FROM rider_profiles WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$rider = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if ($rider) {
    $rider_id = $rider['rider_id'];
}

// Active deliveries assigned
$active_deliveries = 0;
$total_earnings = 0;
if ($rider_id) {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM orders WHERE rider_id = ? AND status NOT IN ('delivered','cancelled')");
    mysqli_stmt_bind_param($stmt, 'i', $rider_id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $active_deliveries = mysqli_fetch_assoc($r)['cnt'];
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM earnings WHERE rider_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $rider_id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $total_earnings = mysqli_fetch_assoc($r)['total'];
    mysqli_stmt_close($stmt);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Rider Dashboard — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/rider.css">
</head>

<body>
    <h1>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></h1>
    <p>Vehicle: <?php echo htmlspecialchars($rider['vehicle_type'] ?? 'Not set'); ?> — Status: <?php echo htmlspecialchars($rider['availability_status'] ?? 'offline'); ?></p>

    <div class="stats">
        <div class="stat-card">
            <p>Active Deliveries</p>
            <h2><?php echo $active_deliveries; ?></h2>
            <a href="deliveries.php">View</a>
        </div>
        <div class="stat-card">
            <p>Total Earnings</p>
            <h2>৳<?php echo number_format($total_earnings, 2); ?></h2>
            <a href="earnings_calculator.php">Breakdown</a>
        </div>
    </div>

    <a href="profile.php">Update Profile</a>
    <a href="../logout.php">Logout</a>
</body>

</html>