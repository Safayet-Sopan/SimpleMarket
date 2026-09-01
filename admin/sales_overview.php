<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('admin');

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

/** @var mysqli $conn */

$fromErr = $toErr = "";
$date_from = cleanInput($_GET['date_from'] ?? '');
$date_to = cleanInput($_GET['date_to'] ?? '');

// Dates are optional, but must be well formed if supplied
if ($date_from !== '' && !preg_match("/^\d{4}-\d{2}-\d{2}$/", $date_from)) {
    $fromErr = "Use the format YYYY-MM-DD";
    $date_from = '';
}
if ($date_to !== '' && !preg_match("/^\d{4}-\d{2}-\d{2}$/", $date_to)) {
    $toErr = "Use the format YYYY-MM-DD";
    $date_to = '';
}

// Sort column is whitelisted — never interpolate a user value into SQL
$sort_columns = [
    'sales'      => 'gross_sales',
    'commission' => 'commission_earned',
    'orders'     => 'delivered_orders',
    'shop'       => 'sp.shop_name',
];
$sort = $_GET['sort'] ?? 'sales';
if (!array_key_exists($sort, $sort_columns)) {
    $sort = 'sales';
}
$dir = (($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
$order_by = $sort_columns[$sort] . ' ' . $dir;

// Build the shared date filter once, for both the per-seller and total queries
$date_filter = "";
$filter_params = [];
$filter_types = "";

if ($date_from !== '') {
    $date_filter .= " AND o.created_at >= ?";
    $filter_params[] = $date_from . " 00:00:00";
    $filter_types .= "s";
}
if ($date_to !== '') {
    $date_filter .= " AND o.created_at <= ?";
    $filter_params[] = $date_to . " 23:59:59";
    $filter_types .= "s";
}

// Per-seller sales. The date filter sits in the JOIN so sellers with no orders
// in the window still appear, with zeroes, rather than dropping out.
$sql = "
    SELECT sp.seller_id, sp.shop_name, sp.commission_rate, u.full_name,
           COUNT(CASE WHEN o.status = 'delivered' THEN 1 END) AS delivered_orders,
           COUNT(CASE WHEN o.status = 'cancelled' THEN 1 END) AS cancelled_orders,
           COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN o.subtotal END), 0) AS gross_sales,
           COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN o.commission_amount END), 0) AS commission_earned
    FROM seller_profiles sp
    JOIN users u ON u.user_id = sp.user_id
    LEFT JOIN orders o ON o.seller_id = sp.seller_id" . $date_filter . "
    WHERE sp.approval_status = 'approved'
    GROUP BY sp.seller_id, sp.shop_name, sp.commission_rate, u.full_name
    ORDER BY " . $order_by;

$stmt = mysqli_prepare($conn, $sql);
if ($filter_types !== '') {
    mysqli_stmt_bind_param($stmt, $filter_types, ...$filter_params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$sellers = [];
while ($row = mysqli_fetch_assoc($result)) {
    $sellers[] = $row;
}
mysqli_stmt_close($stmt);

// Platform totals across the same window
$sql = "
    SELECT COUNT(CASE WHEN o.status = 'delivered' THEN 1 END) AS delivered_orders,
           COUNT(*) AS all_orders,
           COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN o.subtotal END), 0) AS gross_sales,
           COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN o.commission_amount END), 0) AS commission_earned,
           COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN o.delivery_fee END), 0) AS delivery_fees
    FROM orders o
    WHERE 1 = 1" . $date_filter;

$stmt = mysqli_prepare($conn, $sql);
if ($filter_types !== '') {
    mysqli_stmt_bind_param($stmt, $filter_types, ...$filter_params);
}
mysqli_stmt_execute($stmt);
$totals = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

// Best seller by gross sales, for the headline card. Computed independently of
// the table sort, so it stays correct whichever column the admin sorts by.
$top_shop = '—';
$best_sales = 0;
foreach ($sellers as $s) {
    if ($s['gross_sales'] > $best_sales) {
        $best_sales = $s['gross_sales'];
        $top_shop = $s['shop_name'];
    }
}

// Helper for building sortable column links that keep the current filters
function sort_link($column, $label, $current_sort, $dir, $date_from, $date_to)
{
    $next_dir = ($current_sort === $column && $dir === 'DESC') ? 'asc' : 'desc';
    $url = 'sales_overview.php?sort=' . urlencode($column) . '&dir=' . $next_dir;
    if ($date_from !== '') {
        $url .= '&date_from=' . urlencode($date_from);
    }
    if ($date_to !== '') {
        $url .= '&date_to=' . urlencode($date_to);
    }
    $marker = ($current_sort === $column) ? ($dir === 'DESC' ? ' v' : ' ^') : '';
    return '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($label . $marker) . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Sales Overview — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body>
    <h1>Sales Overview of Sellers</h1>

    <form method="GET" action="sales_overview.php">
        <label>From</label>
        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
        <span class="error"><?php echo $fromErr; ?></span>

        <label>To</label>
        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
        <span class="error"><?php echo $toErr; ?></span>

        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
        <input type="hidden" name="dir" value="<?php echo $dir === 'ASC' ? 'asc' : 'desc'; ?>">
        <button type="submit">Apply</button>
        <a href="sales_overview.php">Clear</a>
    </form>

    <div class="stats">
        <div class="stat-card">
            <p>Gross Sales</p>
            <h2>৳<?php echo number_format($totals['gross_sales'], 2); ?></h2>
        </div>
        <div class="stat-card">
            <p>Platform Commission</p>
            <h2>৳<?php echo number_format($totals['commission_earned'], 2); ?></h2>
        </div>
        <div class="stat-card">
            <p>Delivery Fees</p>
            <h2>৳<?php echo number_format($totals['delivery_fees'], 2); ?></h2>
        </div>
        <div class="stat-card">
            <p>Delivered / All Orders</p>
            <h2><?php echo $totals['delivered_orders']; ?> / <?php echo $totals['all_orders']; ?></h2>
        </div>
        <div class="stat-card">
            <p>Top Shop</p>
            <h2><?php echo htmlspecialchars($top_shop); ?></h2>
        </div>
    </div>

    <?php if (empty($sellers)): ?>
        <p>No approved sellers yet.</p>
    <?php else: ?>
        <table border="1" cellpadding="8">
            <tr>
                <th><?php echo sort_link('shop', 'Shop', $sort, $dir, $date_from, $date_to); ?></th>
                <th>Owner</th>
                <th><?php echo sort_link('orders', 'Delivered', $sort, $dir, $date_from, $date_to); ?></th>
                <th>Cancelled</th>
                <th><?php echo sort_link('sales', 'Gross Sales', $sort, $dir, $date_from, $date_to); ?></th>
                <th><?php echo sort_link('commission', 'Commission', $sort, $dir, $date_from, $date_to); ?></th>
                <th>Net Payout</th>
                <th>Avg Order</th>
                <th>Rate</th>
            </tr>
            <?php foreach ($sellers as $s): ?>
                <?php
                $net_payout = $s['gross_sales'] - $s['commission_earned'];
                $avg_order = $s['delivered_orders'] > 0 ? $s['gross_sales'] / $s['delivered_orders'] : 0;
                ?>
                <tr class="<?php echo $s['delivered_orders'] == 0 ? 'notice' : ''; ?>">
                    <td><?php echo htmlspecialchars($s['shop_name']); ?></td>
                    <td><?php echo htmlspecialchars($s['full_name']); ?></td>
                    <td><?php echo $s['delivered_orders']; ?></td>
                    <td><?php echo $s['cancelled_orders']; ?></td>
                    <td>৳<?php echo number_format($s['gross_sales'], 2); ?></td>
                    <td>৳<?php echo number_format($s['commission_earned'], 2); ?></td>
                    <td>৳<?php echo number_format($net_payout, 2); ?></td>
                    <td>৳<?php echo number_format($avg_order, 2); ?></td>
                    <td><?php echo number_format($s['commission_rate'], 2); ?>%</td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <a href="commission_calculator.php">Commission Calculator</a>
    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>
