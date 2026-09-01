<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../config.php';
require_role('rider');

/** @var mysqli $conn */

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

$user_id = $_SESSION['user_id'];

$stmt = mysqli_prepare($conn, "SELECT rider_id FROM rider_profiles WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$rider = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
$rider_id = $rider['rider_id'] ?? null;

$fromErr = $toErr = "";
$date_from = cleanInput($_GET['date_from'] ?? '');
$date_to = cleanInput($_GET['date_to'] ?? '');

if ($date_from !== '' && !preg_match("/^\d{4}-\d{2}-\d{2}$/", $date_from)) {
    $fromErr = "Use the format YYYY-MM-DD";
    $date_from = '';
}
if ($date_to !== '' && !preg_match("/^\d{4}-\d{2}-\d{2}$/", $date_to)) {
    $toErr = "Use the format YYYY-MM-DD";
    $date_to = '';
}

$summary = ['deliveries' => 0, 'total' => 0, 'fast_jobs' => 0, 'fees_carried' => 0];
$rows = [];
$best_day = null;

if ($rider_id) {
    // Shared date filter for both queries below
    $filter = "";
    $params = [$rider_id];
    $types = "i";

    if ($date_from !== '') {
        $filter .= " AND e.earned_at >= ?";
        $params[] = $date_from . " 00:00:00";
        $types .= "s";
    }
    if ($date_to !== '') {
        $filter .= " AND e.earned_at <= ?";
        $params[] = $date_to . " 23:59:59";
        $types .= "s";
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS deliveries,
                COALESCE(SUM(e.amount), 0) AS total,
                COALESCE(SUM(CASE WHEN o.fast_delivery = 1 THEN 1 ELSE 0 END), 0) AS fast_jobs,
                COALESCE(SUM(o.delivery_fee), 0) AS fees_carried
         FROM earnings e
         JOIN orders o ON o.order_id = e.order_id
         WHERE e.rider_id = ?" . $filter
    );
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $summary = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    // Per-delivery detail
    $stmt = mysqli_prepare(
        $conn,
        "SELECT e.amount, e.earned_at, o.order_id, o.delivery_fee, o.fast_delivery,
                o.delivery_address, sp.shop_name
         FROM earnings e
         JOIN orders o ON o.order_id = e.order_id
         JOIN seller_profiles sp ON sp.seller_id = o.seller_id
         WHERE e.rider_id = ?" . $filter . "
         ORDER BY e.earned_at DESC"
    );
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);

    // Day-by-day totals, so a rider can see which days actually pay
    $stmt = mysqli_prepare(
        $conn,
        "SELECT DATE(e.earned_at) AS day, COUNT(*) AS jobs, SUM(e.amount) AS earned
         FROM earnings e
         WHERE e.rider_id = ?" . $filter . "
         GROUP BY DATE(e.earned_at)
         ORDER BY earned DESC
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $best_day = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

$average = $summary['deliveries'] > 0 ? $summary['total'] / $summary['deliveries'] : 0;
$platform_share = $summary['fees_carried'] - $summary['total'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Earnings Calculator — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/rider.css">
</head>

<body>
    <h1>Earnings Calculator</h1>

    <?php if (!$rider_id): ?>
        <p class="error">No rider profile found for this account.</p>
    <?php else: ?>

        <p class="notice">You keep <?php echo number_format(RIDER_EARNING_RATE * 100, 0); ?>%
            of the delivery fee on every completed delivery. Earnings are recorded the
            moment you mark an order delivered.</p>

        <form method="GET" action="earnings_calculator.php">
            <label>From</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            <span class="error"><?php echo $fromErr; ?></span>

            <label>To</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            <span class="error"><?php echo $toErr; ?></span>

            <button type="submit">Apply</button>
            <a href="earnings_calculator.php">Clear</a>
        </form>

        <div class="stats">
            <div class="stat-card">
                <p>Total Earned</p>
                <h2>৳<?php echo number_format($summary['total'], 2); ?></h2>
            </div>
            <div class="stat-card">
                <p>Deliveries Completed</p>
                <h2><?php echo $summary['deliveries']; ?></h2>
            </div>
            <div class="stat-card">
                <p>Average Per Delivery</p>
                <h2>৳<?php echo number_format($average, 2); ?></h2>
            </div>
            <div class="stat-card">
                <p>Fast Deliveries</p>
                <h2><?php echo $summary['fast_jobs']; ?></h2>
                <p>of <?php echo $summary['deliveries']; ?></p>
            </div>
            <div class="stat-card">
                <p>Best Day</p>
                <?php if ($best_day && $best_day['day'] !== null): ?>
                    <h2>৳<?php echo number_format($best_day['earned'], 2); ?></h2>
                    <p><?php echo htmlspecialchars($best_day['day']); ?>
                        (<?php echo $best_day['jobs']; ?> job(s))</p>
                <?php else: ?>
                    <h2>—</h2>
                <?php endif; ?>
            </div>
        </div>

        <p>Delivery fees carried: ৳<?php echo number_format($summary['fees_carried'], 2); ?>
            — your share ৳<?php echo number_format($summary['total'], 2); ?>,
            platform share ৳<?php echo number_format($platform_share, 2); ?>.</p>

        <h2>Every Delivery</h2>

        <?php if (empty($rows)): ?>
            <p>No completed deliveries in this period.</p>
        <?php else: ?>
            <table border="1" cellpadding="8">
                <tr>
                    <th>Order</th>
                    <th>Shop</th>
                    <th>Delivered To</th>
                    <th>Type</th>
                    <th>Delivery Fee</th>
                    <th>Your Share</th>
                    <th>Completed</th>
                </tr>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>#<?php echo $r['order_id']; ?></td>
                        <td><?php echo htmlspecialchars($r['shop_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['delivery_address']); ?></td>
                        <td><?php echo $r['fast_delivery'] ? 'Fast' : 'Standard'; ?></td>
                        <td>৳<?php echo number_format($r['delivery_fee'], 2); ?></td>
                        <td>৳<?php echo number_format($r['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($r['earned_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

    <?php endif; ?>

    <a href="deliveries.php">Deliveries</a>
    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>
