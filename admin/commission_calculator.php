<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('admin');

$rateErr = $actionErr = "";
$successMsg = "";

/** @var mysqli $conn */

// Update a seller's commission rate. Existing orders keep the commission_amount
// stored at checkout — a rate change only affects orders placed from now on.
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $seller_id = $_POST["seller_id"] ?? '';
    $commission_rate = $_POST["commission_rate"] ?? '';

    if (!ctype_digit((string)$seller_id)) {
        $actionErr = "Invalid seller.";
    }

    if ($commission_rate === '') {
        $rateErr = "Commission rate is required";
    } elseif (!is_numeric($commission_rate)) {
        $rateErr = "Commission rate must be a number";
    } elseif ((float)$commission_rate < 0 || (float)$commission_rate > 100) {
        $rateErr = "Commission rate must be between 0 and 100";
    }

    if (!$rateErr && !$actionErr) {
        $seller_id = (int) $seller_id;
        $commission_rate = round((float) $commission_rate, 2);

        $stmt = mysqli_prepare($conn, "SELECT user_id, shop_name FROM seller_profiles WHERE seller_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $seller_id);
        mysqli_stmt_execute($stmt);
        $seller = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$seller) {
            $actionErr = "Seller not found.";
        } else {
            // Rate change + seller notification succeed or fail together
            mysqli_begin_transaction($conn);
            try {
                $stmt = mysqli_prepare($conn, "UPDATE seller_profiles SET commission_rate = ? WHERE seller_id = ?");
                mysqli_stmt_bind_param($stmt, 'di', $commission_rate, $seller_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $notif_message = "Your commission rate is now " . number_format($commission_rate, 2) . "%. This applies to new orders only.";
                $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                mysqli_stmt_bind_param($stmt, 'is', $seller['user_id'], $notif_message);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                mysqli_commit($conn);
                $successMsg = "Commission rate for '" . $seller['shop_name'] . "' updated to " . number_format($commission_rate, 2) . "%.";
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $actionErr = "Something went wrong. Please try again.";
            }
        }
    }
}

// Platform-wide commission totals. Only delivered orders count as earned.
$totals = ['earned' => 0, 'pending' => 0, 'delivered_orders' => 0];
$result = mysqli_query(
    $conn,
    "SELECT
        COALESCE(SUM(CASE WHEN status = 'delivered' THEN commission_amount END), 0) AS earned,
        COALESCE(SUM(CASE WHEN status NOT IN ('delivered','cancelled') THEN commission_amount END), 0) AS pending,
        COUNT(CASE WHEN status = 'delivered' THEN 1 END) AS delivered_orders
     FROM orders"
);
if ($row = mysqli_fetch_assoc($result)) {
    $totals = $row;
}

// Per-seller commission breakdown
$sellers = [];
$result = mysqli_query(
    $conn,
    "SELECT sp.seller_id, sp.shop_name, sp.commission_rate, u.full_name,
            COUNT(CASE WHEN o.status = 'delivered' THEN 1 END) AS delivered_orders,
            COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN o.commission_amount END), 0) AS commission_earned,
            COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN o.subtotal END), 0) AS gross_sales
     FROM seller_profiles sp
     JOIN users u ON u.user_id = sp.user_id
     LEFT JOIN orders o ON o.seller_id = sp.seller_id
     WHERE sp.approval_status = 'approved'
     GROUP BY sp.seller_id, sp.shop_name, sp.commission_rate, u.full_name
     ORDER BY commission_earned DESC"
);
while ($row = mysqli_fetch_assoc($result)) {
    $sellers[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Commission Calculator — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body>
    <h1>Commission Calculator</h1>

    <?php if ($successMsg): ?>
        <p class="success"><?php echo htmlspecialchars($successMsg); ?></p>
    <?php endif; ?>
    <?php if ($actionErr): ?>
        <p class="error"><?php echo $actionErr; ?></p>
    <?php endif; ?>

    <div class="stats">
        <div class="stat-card">
            <p>Commission Earned</p>
            <h2>৳<?php echo number_format($totals['earned'], 2); ?></h2>
            <p>from <?php echo $totals['delivered_orders']; ?> delivered order(s)</p>
        </div>
        <div class="stat-card">
            <p>Commission In Progress</p>
            <h2>৳<?php echo number_format($totals['pending'], 2); ?></h2>
            <p>orders not yet delivered</p>
        </div>
    </div>

    <h2>Quick Estimate</h2>
    <p class="notice">Work out the platform cut on an order value before committing to a rate.</p>
    <label>Order subtotal ৳</label>
    <input type="text" id="calc_amount" value="1000">
    <label>Commission rate %</label>
    <input type="text" id="calc_rate" value="10">
    <p>Platform commission: <strong id="calc_commission">৳0.00</strong>
        | Seller receives: <strong id="calc_payout">৳0.00</strong></p>

    <h2>Per-Seller Breakdown</h2>
    <p class="notice">Commission is stored on each order at checkout, so changing a rate
        affects new orders only — earned totals below never change retroactively.</p>

    <?php if (empty($sellers)): ?>
        <p>No approved sellers yet.</p>
    <?php else: ?>
        <table border="1" cellpadding="8">
            <tr>
                <th>Shop</th>
                <th>Owner</th>
                <th>Delivered Orders</th>
                <th>Gross Sales</th>
                <th>Commission Earned</th>
                <th>Current Rate</th>
                <th>Set New Rate</th>
            </tr>
            <?php foreach ($sellers as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['shop_name']); ?></td>
                    <td><?php echo htmlspecialchars($s['full_name']); ?></td>
                    <td><?php echo $s['delivered_orders']; ?></td>
                    <td>৳<?php echo number_format($s['gross_sales'], 2); ?></td>
                    <td>৳<?php echo number_format($s['commission_earned'], 2); ?></td>
                    <td><?php echo number_format($s['commission_rate'], 2); ?>%</td>
                    <td>
                        <form method="POST" action="commission_calculator.php">
                            <input type="hidden" name="seller_id" value="<?php echo $s['seller_id']; ?>">
                            <input type="text" name="commission_rate" size="5"
                                value="<?php echo htmlspecialchars($s['commission_rate']); ?>">
                            <button type="submit">Update</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <span class="error"><?php echo $rateErr; ?></span>
    <?php endif; ?>

    <a href="sales_overview.php">Sales Overview</a>
    <a href="dashboard.php">Back to Dashboard</a>

    <script>
        // Live estimate — recalculates as you type, no page reload
        var amountInput = document.getElementById('calc_amount');
        var rateInput = document.getElementById('calc_rate');
        var commissionOut = document.getElementById('calc_commission');
        var payoutOut = document.getElementById('calc_payout');

        function formatTaka(value) {
            return '৳' + value.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function recalculate() {
            var amount = parseFloat(amountInput.value);
            var rate = parseFloat(rateInput.value);

            if (isNaN(amount) || isNaN(rate) || amount < 0 || rate < 0 || rate > 100) {
                commissionOut.textContent = '—';
                payoutOut.textContent = '—';
                return;
            }

            var commission = amount * (rate / 100);
            commissionOut.textContent = formatTaka(commission);
            payoutOut.textContent = formatTaka(amount - commission);
        }

        amountInput.addEventListener('input', recalculate);
        rateInput.addEventListener('input', recalculate);
        recalculate();
    </script>
</body>

</html>
