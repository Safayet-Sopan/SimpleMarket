<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('customer');

$customer_id = $_SESSION['user_id'];
$order_id = $_GET['order_id'] ?? '';

// The status timeline every non-cancelled order moves through, in order.
$timeline = ['pending', 'confirmed', 'preparing', 'out_for_delivery', 'delivered'];

$status_labels = [
    'pending'          => 'Order placed — waiting for the shop to confirm',
    'confirmed'        => 'Confirmed by the shop',
    'preparing'        => 'Being prepared',
    'out_for_delivery' => 'Out for delivery',
    'delivered'        => 'Delivered',
    'cancelled'        => 'Cancelled',
];

$order = null;
$items = [];
$orders = [];

/** @var mysqli $conn */
if (ctype_digit((string)$order_id)) {
    // Detail view — scoped to this customer so order_id cannot be walked
    $order_id = (int) $order_id;
    $stmt = mysqli_prepare(
        $conn,
        "SELECT o.order_id, o.delivery_address, o.fast_delivery, o.delivery_fee, o.subtotal,
                o.total_amount, o.status, o.created_at, o.rider_id,
                sp.shop_name,
                u.full_name AS rider_name, rp.vehicle_type
         FROM orders o
         JOIN seller_profiles sp ON sp.seller_id = o.seller_id
         LEFT JOIN rider_profiles rp ON rp.rider_id = o.rider_id
         LEFT JOIN users u ON u.user_id = rp.user_id
         WHERE o.order_id = ? AND o.customer_id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $order_id, $customer_id);
    mysqli_stmt_execute($stmt);
    $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($order) {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT oi.quantity, oi.unit_price, oi.line_total, p.product_name
             FROM order_items oi
             JOIN products p ON p.product_id = oi.product_id
             WHERE oi.order_id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'i', $order_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $items[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
} else {
    // List view — every order this customer has placed
    $stmt = mysqli_prepare(
        $conn,
        "SELECT o.order_id, o.total_amount, o.status, o.created_at, o.fast_delivery, sp.shop_name
         FROM orders o
         JOIN seller_profiles sp ON sp.seller_id = o.seller_id
         WHERE o.customer_id = ?
         ORDER BY o.created_at DESC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $customer_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $orders[] = $row;
    }
    mysqli_stmt_close($stmt);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Order Tracking — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/customer.css">
</head>

<body>
    <h1>Order Tracking</h1>

    <?php if (ctype_digit((string)$order_id) && !$order): ?>

        <p class="error">Order not found.</p>

    <?php elseif ($order): ?>

        <p class="success">Order #<?php echo $order['order_id']; ?> placed on
            <?php echo htmlspecialchars($order['created_at']); ?></p>

        <h2>Status</h2>
        <?php if ($order['status'] === 'cancelled'): ?>
            <p class="critical">This order was cancelled.</p>
        <?php else: ?>
            <?php $current_step = array_search($order['status'], $timeline, true); ?>
            <table border="1" cellpadding="8">
                <?php foreach ($timeline as $i => $step): ?>
                    <tr class="<?php echo $i <= $current_step ? 'success' : 'notice'; ?>">
                        <td><?php echo $i <= $current_step ? 'DONE' : 'PENDING'; ?></td>
                        <td><?php echo htmlspecialchars($status_labels[$step]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

        <h2>Details</h2>
        <p>Shop: <?php echo htmlspecialchars($order['shop_name']); ?></p>
        <p>Deliver to: <?php echo htmlspecialchars($order['delivery_address']); ?></p>
        <p>Delivery type: <?php echo $order['fast_delivery'] ? 'Fast Delivery' : 'Standard'; ?></p>
        <p>Rider:
            <?php if ($order['rider_id']): ?>
                <?php echo htmlspecialchars($order['rider_name']); ?>
                (<?php echo htmlspecialchars($order['vehicle_type']); ?>)
            <?php else: ?>
                Not assigned yet
            <?php endif; ?>
        </p>

        <table border="1" cellpadding="8">
            <tr>
                <th>Product</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Line Total</th>
            </tr>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                    <td><?php echo $item['quantity']; ?></td>
                    <td>৳<?php echo number_format($item['unit_price'], 2); ?></td>
                    <td>৳<?php echo number_format($item['line_total'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <p>Subtotal: ৳<?php echo number_format($order['subtotal'], 2); ?></p>
        <p>Delivery fee: ৳<?php echo number_format($order['delivery_fee'], 2); ?></p>
        <p><strong>Total: ৳<?php echo number_format($order['total_amount'], 2); ?></strong></p>

        <?php if ($order['status'] === 'delivered'): ?>
            <a href="product_feedback.php?order_id=<?php echo $order['order_id']; ?>">Leave Product Feedback</a>
            <a href="seller_rating.php?order_id=<?php echo $order['order_id']; ?>">Rate the Seller</a>
        <?php endif; ?>

        <p><a href="order_tracking.php">All Orders</a></p>

    <?php else: ?>

        <?php if (empty($orders)): ?>
            <p>You have not placed any orders yet.</p>
        <?php else: ?>
            <table border="1" cellpadding="8">
                <tr>
                    <th>Order</th>
                    <th>Shop</th>
                    <th>Placed</th>
                    <th>Delivery</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td>#<?php echo $o['order_id']; ?></td>
                        <td><?php echo htmlspecialchars($o['shop_name']); ?></td>
                        <td><?php echo htmlspecialchars($o['created_at']); ?></td>
                        <td><?php echo $o['fast_delivery'] ? 'Fast' : 'Standard'; ?></td>
                        <td>৳<?php echo number_format($o['total_amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($status_labels[$o['status']]); ?></td>
                        <td><a href="order_tracking.php?order_id=<?php echo $o['order_id']; ?>">Track</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

    <?php endif; ?>

    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>
