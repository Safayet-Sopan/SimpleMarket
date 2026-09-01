<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../config.php';
require_role('rider');

/** @var mysqli $conn */

$user_id = $_SESSION['user_id'];

$stmt = mysqli_prepare(
    $conn,
    "SELECT rider_id, vehicle_type, availability_status FROM rider_profiles WHERE user_id = ?"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$rider = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
$rider_id = $rider['rider_id'] ?? null;

$actionErr = "";
$successMsg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && $rider_id) {
    $order_id = $_POST['order_id'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!ctype_digit((string)$order_id) || !in_array($action, ['claim', 'out_for_delivery', 'delivered'], true)) {
        $actionErr = "Invalid request.";
    } else {
        $order_id = (int) $order_id;

        if ($action === 'claim') {
            // Claiming is a single guarded UPDATE. If another rider got there
            // first, rider_id is no longer NULL and this changes zero rows.
            mysqli_begin_transaction($conn);
            try {
                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE orders SET rider_id = ?
                     WHERE order_id = ? AND rider_id IS NULL
                       AND status IN ('confirmed','preparing')"
                );
                mysqli_stmt_bind_param($stmt, 'ii', $rider_id, $order_id);
                mysqli_stmt_execute($stmt);
                $claimed = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);

                if ($claimed === 0) {
                    throw new Exception('Already claimed');
                }

                // A rider holding a delivery is busy
                $stmt = mysqli_prepare($conn, "UPDATE rider_profiles SET availability_status = 'busy' WHERE rider_id = ?");
                mysqli_stmt_bind_param($stmt, 'i', $rider_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                // Tell the customer and the shop who is carrying it
                $notif_message = "A rider has picked up order #{$order_id}.";
                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO notifications (user_id, message)
                     SELECT o.customer_id, ? FROM orders o WHERE o.order_id = ?"
                );
                mysqli_stmt_bind_param($stmt, 'si', $notif_message, $order_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                mysqli_commit($conn);
                $successMsg = "Order #{$order_id} is yours.";
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $actionErr = "Another rider claimed that order first.";
            }

        } else {
            // Advancing a delivery. The WHERE clause pins both the owner and the
            // expected current status, so a stale form cannot skip a step.
            $required_status = ($action === 'out_for_delivery') ? 'preparing' : 'out_for_delivery';

            mysqli_begin_transaction($conn);
            try {
                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE orders SET status = ?
                     WHERE order_id = ? AND rider_id = ? AND status = ?"
                );
                mysqli_stmt_bind_param($stmt, 'siis', $action, $order_id, $rider_id, $required_status);
                mysqli_stmt_execute($stmt);
                $changed = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);

                if ($changed === 0) {
                    throw new Exception('Not allowed from the current status');
                }

                if ($action === 'delivered') {
                    // Rider keeps a share of the delivery fee. The guarded UPDATE
                    // above runs once per order, so this cannot double-pay.
                    $stmt = mysqli_prepare(
                        $conn,
                        "INSERT INTO earnings (rider_id, order_id, amount)
                         SELECT ?, order_id, ROUND(delivery_fee * ?, 2)
                         FROM orders WHERE order_id = ?"
                    );
                    $rate = RIDER_EARNING_RATE;
                    mysqli_stmt_bind_param($stmt, 'idi', $rider_id, $rate, $order_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    // Free the rider up for the next job
                    $stmt = mysqli_prepare(
                        $conn,
                        "UPDATE rider_profiles SET availability_status = 'available' WHERE rider_id = ?"
                    );
                    mysqli_stmt_bind_param($stmt, 'i', $rider_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    $notif_message = "Order #{$order_id} was delivered. You can now review the product and rate the shop.";
                } else {
                    $notif_message = "Order #{$order_id} is on its way to you.";
                }

                $stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO notifications (user_id, message)
                     SELECT o.customer_id, ? FROM orders o WHERE o.order_id = ?"
                );
                mysqli_stmt_bind_param($stmt, 'si', $notif_message, $order_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                mysqli_commit($conn);
                $successMsg = "Order #{$order_id} marked " . str_replace('_', ' ', $action) . ".";
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $actionErr = "Could not update that delivery. Refresh and try again.";
            }
        }
    }
}

// Orders this rider is carrying
$my_deliveries = [];
// Orders any rider can take
$available_orders = [];

if ($rider_id) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT o.order_id, o.status, o.delivery_address, o.delivery_fee, o.total_amount,
                o.fast_delivery, o.payment_method, o.payment_status, o.created_at,
                sp.shop_name, sp.shop_address,
                u.full_name AS customer_name, u.phone AS customer_phone
         FROM orders o
         JOIN seller_profiles sp ON sp.seller_id = o.seller_id
         JOIN users u ON u.user_id = o.customer_id
         WHERE o.rider_id = ? AND o.status NOT IN ('delivered','cancelled')
         ORDER BY o.fast_delivery DESC, o.created_at ASC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $rider_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $my_deliveries[] = $row;
    }
    mysqli_stmt_close($stmt);

    // Fast-delivery orders surface first — that is what the customer paid for
    $result = mysqli_query(
        $conn,
        "SELECT o.order_id, o.status, o.delivery_address, o.delivery_fee, o.fast_delivery,
                o.created_at, sp.shop_name, sp.shop_address
         FROM orders o
         JOIN seller_profiles sp ON sp.seller_id = o.seller_id
         WHERE o.rider_id IS NULL AND o.status IN ('confirmed','preparing')
         ORDER BY o.fast_delivery DESC, o.created_at ASC"
    );
    while ($row = mysqli_fetch_assoc($result)) {
        $available_orders[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Deliveries — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/rider.css">
</head>

<body>
    <h1>Deliveries</h1>

    <?php if ($successMsg): ?>
        <p class="success"><?php echo htmlspecialchars($successMsg); ?></p>
    <?php endif; ?>
    <?php if ($actionErr): ?>
        <p class="error"><?php echo htmlspecialchars($actionErr); ?></p>
    <?php endif; ?>

    <?php if (!$rider_id): ?>
        <p class="error">No rider profile found for this account.
            <a href="profile.php">Set up your vehicle</a> first.</p>
    <?php else: ?>

        <h2>Carrying Now (<?php echo count($my_deliveries); ?>)</h2>

        <?php if (empty($my_deliveries)): ?>
            <p>You are not carrying anything. Claim one from the list below.</p>
        <?php else: ?>
            <table border="1" cellpadding="8">
                <tr>
                    <th>Order</th>
                    <th>Pick Up From</th>
                    <th>Deliver To</th>
                    <th>Payment</th>
                    <th>You Earn</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                <?php foreach ($my_deliveries as $d): ?>
                    <tr class="<?php echo $d['fast_delivery'] ? 'alert' : 'notice'; ?>">
                        <td>
                            #<?php echo $d['order_id']; ?>
                            <?php if ($d['fast_delivery']): ?><br><strong>FAST</strong><?php endif; ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($d['shop_name']); ?><br>
                            <small><?php echo htmlspecialchars($d['shop_address']); ?></small>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($d['customer_name']); ?><br>
                            <small><?php echo htmlspecialchars($d['customer_phone']); ?></small><br>
                            <small><?php echo htmlspecialchars($d['delivery_address']); ?></small>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($PAYMENT_METHODS[$d['payment_method']] ?? $d['payment_method']); ?><br>
                            <strong><?php echo htmlspecialchars($d['payment_status']); ?></strong>
                            <?php if ($d['payment_status'] === 'unpaid' && $d['payment_method'] === 'cod'): ?>
                                <br><small class="critical">Collect ৳<?php echo number_format($d['total_amount'], 2); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>৳<?php echo number_format($d['delivery_fee'] * RIDER_EARNING_RATE, 2); ?></td>
                        <td><?php echo str_replace('_', ' ', $d['status']); ?></td>
                        <td>
                            <?php if ($d['status'] === 'preparing'): ?>
                                <form method="POST" action="deliveries.php">
                                    <input type="hidden" name="order_id" value="<?php echo $d['order_id']; ?>">
                                    <button type="submit" name="action" value="out_for_delivery">Start Delivery</button>
                                </form>
                            <?php elseif ($d['status'] === 'out_for_delivery'): ?>
                                <form method="POST" action="deliveries.php">
                                    <input type="hidden" name="order_id" value="<?php echo $d['order_id']; ?>">
                                    <button type="submit" name="action" value="delivered">Mark Delivered</button>
                                </form>
                            <?php else: ?>
                                <small>Waiting for the shop to finish preparing.</small>
                            <?php endif; ?>
                            <a href="chatbox.php?order_id=<?php echo $d['order_id']; ?>">Chat</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

        <h2>Available to Claim (<?php echo count($available_orders); ?>)</h2>

        <?php if (empty($available_orders)): ?>
            <p>Nothing waiting for a rider right now.</p>
        <?php else: ?>
            <table border="1" cellpadding="8">
                <tr>
                    <th>Order</th>
                    <th>Pick Up From</th>
                    <th>Deliver To</th>
                    <th>You Earn</th>
                    <th>Shop Status</th>
                    <th>Action</th>
                </tr>
                <?php foreach ($available_orders as $a): ?>
                    <tr class="<?php echo $a['fast_delivery'] ? 'alert' : ''; ?>">
                        <td>
                            #<?php echo $a['order_id']; ?>
                            <?php if ($a['fast_delivery']): ?><br><strong>FAST</strong><?php endif; ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($a['shop_name']); ?><br>
                            <small><?php echo htmlspecialchars($a['shop_address']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($a['delivery_address']); ?></td>
                        <td>৳<?php echo number_format($a['delivery_fee'] * RIDER_EARNING_RATE, 2); ?></td>
                        <td><?php echo str_replace('_', ' ', $a['status']); ?></td>
                        <td>
                            <form method="POST" action="deliveries.php">
                                <input type="hidden" name="order_id" value="<?php echo $a['order_id']; ?>">
                                <button type="submit" name="action" value="claim">Claim</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

    <?php endif; ?>

    <a href="earnings_calculator.php">Earnings</a>
    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>
