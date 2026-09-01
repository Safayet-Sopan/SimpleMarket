<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../config.php';
require_once '../includes/order_status.php';
require_role('customer');

/** @var mysqli $conn */

$customer_id = $_SESSION['user_id'];
$actionErr = "";
$successMsg = "";

// A customer may cancel only while the order is still 'pending'
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $order_id = $_POST['order_id'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!ctype_digit((string)$order_id) || $action !== 'cancelled') {
        $actionErr = "Invalid request.";
    } else {
        $order_id = (int) $order_id;

        $stmt = mysqli_prepare(
            $conn,
            "SELECT order_id, status, seller_id FROM orders WHERE order_id = ? AND customer_id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'ii', $order_id, $customer_id);
        mysqli_stmt_execute($stmt);
        $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$order) {
            $actionErr = "Order not found.";
        } elseif (!can_transition('customer', $order['status'], 'cancelled')) {
            $actionErr = "This order has already been accepted by the shop — ask them to cancel it.";
        } else {
            mysqli_begin_transaction($conn);
            try {
                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE orders SET status = 'cancelled'
                     WHERE order_id = ? AND customer_id = ? AND status = 'pending'"
                );
                mysqli_stmt_bind_param($stmt, 'ii', $order_id, $customer_id);
                mysqli_stmt_execute($stmt);
                $changed = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);

                if ($changed === 0) {
                    throw new Exception('Status moved on');
                }

                restore_order_stock($conn, $order_id);

                // Tell the shop
                $notif_message = "Order #{$order_id} was cancelled by the customer.";
                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO notifications (user_id, message)
                     SELECT user_id, ? FROM seller_profiles WHERE seller_id = ?"
                );
                mysqli_stmt_bind_param($stmt, 'si', $notif_message, $order['seller_id']);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                mysqli_commit($conn);
                $successMsg = "Order #{$order_id} cancelled.";
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $actionErr = "Could not cancel — the shop may have just accepted it. Refresh and check.";
            }
        }
    }
}

$filter = $_GET['status'] ?? 'all';
$valid = ['all', 'open', 'delivered', 'cancelled'];
if (!in_array($filter, $valid, true)) {
    $filter = 'all';
}

$sql = "
    SELECT o.order_id, o.status, o.total_amount, o.subtotal, o.delivery_fee, o.fast_delivery,
           o.payment_method, o.payment_status, o.delivery_address, o.created_at,
           sp.shop_name,
           ru.full_name AS rider_name
    FROM orders o
    JOIN seller_profiles sp ON sp.seller_id = o.seller_id
    LEFT JOIN rider_profiles rp ON rp.rider_id = o.rider_id
    LEFT JOIN users ru ON ru.user_id = rp.user_id
    WHERE o.customer_id = ?";

$params = [$customer_id];
$types = "i";

if ($filter === 'open') {
    $sql .= " AND o.status NOT IN ('delivered','cancelled')";
} elseif ($filter !== 'all') {
    $sql .= " AND o.status = ?";
    $params[] = $filter;
    $types .= "s";
}

$sql .= " ORDER BY o.created_at DESC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$orders = [];
while ($row = mysqli_fetch_assoc($result)) {
    $orders[] = $row;
}
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Orders — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/customer.css">
</head>

<body>
    <h1>My Orders</h1>

    <?php if ($successMsg): ?>
        <p class="success"><?php echo htmlspecialchars($successMsg); ?></p>
    <?php endif; ?>
    <?php if ($actionErr): ?>
        <p class="error"><?php echo htmlspecialchars($actionErr); ?></p>
    <?php endif; ?>

    <p>
        Show:
        <?php foreach ($valid as $f): ?>
            <?php if ($f === $filter): ?>
                <strong><?php echo $f; ?></strong>
            <?php else: ?>
                <a href="orders.php?status=<?php echo urlencode($f); ?>"><?php echo $f; ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </p>

    <?php if (empty($orders)): ?>
        <p>No orders to show. <a href="search.php">Find something to buy.</a></p>
    <?php else: ?>
        <table border="1" cellpadding="8">
            <tr>
                <th>Order</th>
                <th>Shop</th>
                <th>Delivery</th>
                <th>Payment</th>
                <th>Total</th>
                <th>Rider</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
            <?php foreach ($orders as $o): ?>
                <tr class="<?php echo $o['status'] === 'cancelled' ? 'critical' : 'notice'; ?>">
                    <td>
                        #<?php echo $o['order_id']; ?><br>
                        <small><?php echo htmlspecialchars($o['created_at']); ?></small>
                    </td>
                    <td><?php echo htmlspecialchars($o['shop_name']); ?></td>
                    <td>
                        <?php echo $o['fast_delivery'] ? 'Fast' : 'Standard'; ?><br>
                        <small><?php echo htmlspecialchars($o['delivery_address']); ?></small>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($PAYMENT_METHODS[$o['payment_method']] ?? $o['payment_method']); ?><br>
                        <strong><?php echo htmlspecialchars($o['payment_status']); ?></strong>
                    </td>
                    <td>৳<?php echo number_format($o['total_amount'], 2); ?></td>
                    <td><?php echo $o['rider_name'] ? htmlspecialchars($o['rider_name']) : '—'; ?></td>
                    <td><?php echo str_replace('_', ' ', $o['status']); ?></td>
                    <td>
                        <a href="order_tracking.php?order_id=<?php echo $o['order_id']; ?>">Track</a>
                        <a href="chat.php?order_id=<?php echo $o['order_id']; ?>">Chat</a>

                        <?php if (can_transition('customer', $o['status'], 'cancelled')): ?>
                            <form method="POST" action="orders.php?status=<?php echo urlencode($filter); ?>">
                                <input type="hidden" name="order_id" value="<?php echo $o['order_id']; ?>">
                                <button type="submit" name="action" value="cancelled">Cancel</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($o['status'] === 'delivered'): ?>
                            <a href="product_feedback.php">Review</a>
                            <a href="seller_rating.php">Rate Shop</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <a href="search.php">Browse Products</a>
    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>
