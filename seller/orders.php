<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../config.php';
require_once '../includes/order_status.php';
require_role('seller');

/** @var mysqli $conn */

$user_id = $_SESSION['user_id'];

// Resolve this seller's seller_id
$stmt = mysqli_prepare($conn, "SELECT seller_id, shop_name FROM seller_profiles WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$seller = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
$seller_id = $seller['seller_id'] ?? null;

$actionErr = "";
$successMsg = "";

// What a seller may move an order to, from each status. out_for_delivery and
// delivered belong to the rider — see includes/order_status.php.
$allowed_transitions = order_transitions('seller');

if ($_SERVER["REQUEST_METHOD"] == "POST" && $seller_id) {
    $order_id = $_POST["order_id"] ?? '';
    $action = $_POST["action"] ?? '';

    if (!ctype_digit((string)$order_id)) {
        $actionErr = "Invalid order.";
    } else {
        $order_id = (int) $order_id;

        // Load the order only if it belongs to this seller
        $stmt = mysqli_prepare(
            $conn,
            "SELECT order_id, customer_id, status, payment_status, total_amount
             FROM orders WHERE order_id = ? AND seller_id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'ii', $order_id, $seller_id);
        mysqli_stmt_execute($stmt);
        $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$order) {
            $actionErr = "Order not found.";

        } elseif ($action === 'mark_paid') {
            // Manual payment confirmation — there is no gateway to ask
            if ($order['payment_status'] === 'paid') {
                $actionErr = "That order is already marked paid.";
            } else {
                $stmt = mysqli_prepare($conn, "UPDATE orders SET payment_status = 'paid' WHERE order_id = ?");
                mysqli_stmt_bind_param($stmt, 'i', $order_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $successMsg = "Order #{$order_id} marked as paid.";
            }

        } elseif (!isset($allowed_transitions[$order['status']])
            || !in_array($action, $allowed_transitions[$order['status']], true)) {
            $actionErr = "You cannot move an order from '" . $order['status'] . "' to '" . $action . "'.";

        } else {
            $notif_message = "Order #{$order_id} is now " . str_replace('_', ' ', $action) . ".";

            mysqli_begin_transaction($conn);
            try {
                $stmt = mysqli_prepare($conn, "UPDATE orders SET status = ? WHERE order_id = ? AND status = ?");
                mysqli_stmt_bind_param($stmt, 'sis', $action, $order_id, $order['status']);
                mysqli_stmt_execute($stmt);
                $changed = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);

                if ($changed === 0) {
                    // Someone else moved it between the read and the write
                    throw new Exception('Order status changed elsewhere');
                }

                // Cancelling puts the reserved stock back, or it is lost for good
                if ($action === 'cancelled') {
                    restore_order_stock($conn, $order_id);
                    $notif_message = "Order #{$order_id} was cancelled by the shop.";
                }

                $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                mysqli_stmt_bind_param($stmt, 'is', $order['customer_id'], $notif_message);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                mysqli_commit($conn);
                $successMsg = "Order #{$order_id} updated to " . str_replace('_', ' ', $action) . ".";
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $actionErr = "Could not update that order. Refresh and try again.";
            }
        }
    }
}

// Filter by status, defaulting to everything still in progress
$filter = $_GET['status'] ?? 'open';
$valid_filters = ['open', 'pending', 'confirmed', 'preparing', 'out_for_delivery', 'delivered', 'cancelled', 'all'];
if (!in_array($filter, $valid_filters, true)) {
    $filter = 'open';
}

$orders = [];
if ($seller_id) {
    $sql = "
        SELECT o.order_id, o.status, o.payment_method, o.payment_status, o.subtotal,
               o.delivery_fee, o.commission_amount, o.total_amount, o.fast_delivery,
               o.delivery_address, o.created_at, o.rider_id,
               u.full_name AS customer_name, u.phone AS customer_phone,
               ru.full_name AS rider_name
        FROM orders o
        JOIN users u ON u.user_id = o.customer_id
        LEFT JOIN rider_profiles rp ON rp.rider_id = o.rider_id
        LEFT JOIN users ru ON ru.user_id = rp.user_id
        WHERE o.seller_id = ?";

    $params = [$seller_id];
    $types = "i";

    if ($filter === 'open') {
        $sql .= " AND o.status NOT IN ('delivered','cancelled')";
    } elseif ($filter !== 'all') {
        $sql .= " AND o.status = ?";
        $params[] = $filter;
        $types .= "s";
    }

    $sql .= " ORDER BY FIELD(o.status,'pending','confirmed','preparing','out_for_delivery','delivered','cancelled'), o.created_at DESC";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $orders[] = $row;
    }
    mysqli_stmt_close($stmt);

    // Items for each order, fetched in one query rather than one per row
    if (!empty($orders)) {
        $ids = [];
        foreach ($orders as $o) {
            $ids[] = (int) $o['order_id'];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = mysqli_prepare(
            $conn,
            "SELECT oi.order_id, oi.quantity, oi.unit_price, p.product_name
             FROM order_items oi
             JOIN products p ON p.product_id = oi.product_id
             WHERE oi.order_id IN ($placeholders)"
        );
        mysqli_stmt_bind_param($stmt, str_repeat('i', count($ids)), ...$ids);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $items_by_order = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $items_by_order[$row['order_id']][] = $row;
        }
        mysqli_stmt_close($stmt);

        foreach ($orders as $i => $o) {
            $orders[$i]['items'] = $items_by_order[$o['order_id']] ?? [];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Orders — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/seller.css">
</head>

<body>
    <h1>Orders</h1>

    <?php if ($successMsg): ?>
        <p class="success"><?php echo htmlspecialchars($successMsg); ?></p>
    <?php endif; ?>
    <?php if ($actionErr): ?>
        <p class="error"><?php echo htmlspecialchars($actionErr); ?></p>
    <?php endif; ?>

    <p>
        Show:
        <?php foreach (['open', 'pending', 'confirmed', 'preparing', 'out_for_delivery', 'delivered', 'cancelled', 'all'] as $f): ?>
            <?php if ($f === $filter): ?>
                <strong><?php echo str_replace('_', ' ', $f); ?></strong>
            <?php else: ?>
                <a href="orders.php?status=<?php echo urlencode($f); ?>"><?php echo str_replace('_', ' ', $f); ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </p>

    <?php if (!$seller_id): ?>
        <p class="error">No seller profile found for this account.</p>
    <?php elseif (empty($orders)): ?>
        <p>No orders to show for this filter.</p>
    <?php else: ?>
        <table border="1" cellpadding="8">
            <tr>
                <th>Order</th>
                <th>Customer</th>
                <th>Items</th>
                <th>Delivery</th>
                <th>Payment</th>
                <th>You Receive</th>
                <th>Rider</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
            <?php foreach ($orders as $o): ?>
                <?php $net = $o['subtotal'] - $o['commission_amount']; ?>
                <tr class="<?php echo $o['status'] === 'pending' ? 'alert' : 'notice'; ?>">
                    <td>
                        #<?php echo $o['order_id']; ?><br>
                        <small><?php echo htmlspecialchars($o['created_at']); ?></small>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($o['customer_name']); ?><br>
                        <small><?php echo htmlspecialchars($o['customer_phone']); ?></small><br>
                        <small><?php echo htmlspecialchars($o['delivery_address']); ?></small>
                    </td>
                    <td>
                        <?php foreach ($o['items'] as $item): ?>
                            <?php echo htmlspecialchars($item['product_name']); ?>
                            x<?php echo $item['quantity']; ?>
                            @ ৳<?php echo number_format($item['unit_price'], 2); ?><br>
                        <?php endforeach; ?>
                    </td>
                    <td><?php echo $o['fast_delivery'] ? 'Fast' : 'Standard'; ?></td>
                    <td>
                        <?php echo htmlspecialchars($PAYMENT_METHODS[$o['payment_method']] ?? $o['payment_method']); ?><br>
                        <strong><?php echo htmlspecialchars($o['payment_status']); ?></strong>
                    </td>
                    <td>
                        ৳<?php echo number_format($net, 2); ?><br>
                        <small>after ৳<?php echo number_format($o['commission_amount'], 2); ?> commission</small>
                    </td>
                    <td>
                        <?php echo $o['rider_name']
                            ? htmlspecialchars($o['rider_name'])
                            : 'Unassigned'; ?>
                    </td>
                    <td><?php echo str_replace('_', ' ', $o['status']); ?></td>
                    <td>
                        <?php if (isset($allowed_transitions[$o['status']])): ?>
                            <form method="POST" action="orders.php?status=<?php echo urlencode($filter); ?>">
                                <input type="hidden" name="order_id" value="<?php echo $o['order_id']; ?>">
                                <?php foreach ($allowed_transitions[$o['status']] as $next): ?>
                                    <button type="submit" name="action" value="<?php echo $next; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $next)); ?>
                                    </button>
                                <?php endforeach; ?>
                            </form>
                        <?php endif; ?>

                        <?php if ($o['payment_status'] === 'unpaid' && $o['status'] !== 'cancelled'): ?>
                            <form method="POST" action="orders.php?status=<?php echo urlencode($filter); ?>">
                                <input type="hidden" name="order_id" value="<?php echo $o['order_id']; ?>">
                                <button type="submit" name="action" value="mark_paid">Mark Paid</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($o['status'] === 'preparing'): ?>
                            <small class="notice">Waiting for a rider to pick this up.</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <a href="price_bidding.php">Price Bidding</a>
    <a href="payment_methods.php">Payment Methods</a>
    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>
