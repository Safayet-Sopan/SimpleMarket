<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('seller');

/** @var mysqli $conn */

$user_id = $_SESSION['user_id'];

// Resolve this seller's seller_id
$stmt = mysqli_prepare($conn, "SELECT seller_id FROM seller_profiles WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$seller = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
$seller_id = $seller['seller_id'] ?? null;

$counterErr = $actionErr = "";
$successMsg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && $seller_id) {
    $offer_id = $_POST["offer_id"] ?? '';
    $action = $_POST["action"] ?? '';

    if (!ctype_digit((string)$offer_id) || !in_array($action, ['accept', 'reject', 'counter'], true)) {
        $actionErr = "Invalid request.";
    } else {
        $offer_id = (int) $offer_id;

        // Only load the offer if it belongs to a product this seller owns
        $stmt = mysqli_prepare(
            $conn,
            "SELECT o.offer_id, o.offered_price, o.status, o.customer_id, o.converted_order_id,
                    p.product_name, p.price
             FROM offers o
             JOIN products p ON p.product_id = o.product_id
             WHERE o.offer_id = ? AND p.seller_id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'ii', $offer_id, $seller_id);
        mysqli_stmt_execute($stmt);
        $offer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$offer) {
            $actionErr = "Offer not found.";
        } elseif ($offer['converted_order_id'] !== null) {
            $actionErr = "That bid has already been turned into an order.";
        } elseif (!in_array($offer['status'], ['pending', 'countered'], true)) {
            $actionErr = "That bid has already been settled.";
        } else {

            $counter_price = null;
            if ($action === 'counter') {
                $counter_price = $_POST["counter_price"] ?? '';
                if ($counter_price === '') {
                    $counterErr = "Counter price is required";
                } elseif (!is_numeric($counter_price)) {
                    $counterErr = "Counter price must be a number";
                } elseif ((float)$counter_price <= 0) {
                    $counterErr = "Counter price must be greater than 0";
                } elseif ((float)$counter_price > (float)$offer['price']) {
                    $counterErr = "Counter price cannot exceed the listed price";
                } else {
                    $counter_price = round((float)$counter_price, 2);
                }
            }

            if (!$counterErr) {
                if ($action === 'accept') {
                    $new_status = 'accepted';
                    $notif_message = "Your offer of ৳" . number_format($offer['offered_price'], 2)
                        . " for '" . $offer['product_name'] . "' was accepted. Order now at that price.";
                } elseif ($action === 'reject') {
                    $new_status = 'rejected';
                    $notif_message = "Your offer for '" . $offer['product_name'] . "' was declined.";
                } else {
                    $new_status = 'countered';
                    $notif_message = "The seller countered your offer for '" . $offer['product_name']
                        . "' at ৳" . number_format($counter_price, 2) . ".";
                }

                // Status change + customer notification succeed or fail together
                mysqli_begin_transaction($conn);
                try {
                    if ($action === 'counter') {
                        $stmt = mysqli_prepare($conn, "UPDATE offers SET status = ?, counter_price = ? WHERE offer_id = ?");
                        mysqli_stmt_bind_param($stmt, 'sdi', $new_status, $counter_price, $offer_id);
                    } else {
                        $stmt = mysqli_prepare($conn, "UPDATE offers SET status = ? WHERE offer_id = ?");
                        mysqli_stmt_bind_param($stmt, 'si', $new_status, $offer_id);
                    }
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                    mysqli_stmt_bind_param($stmt, 'is', $offer['customer_id'], $notif_message);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    mysqli_commit($conn);
                    $successMsg = "Bid " . $new_status . ".";
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $actionErr = "Something went wrong. Please try again.";
                }
            }
        }
    }
}

// All bids on this seller's products, open ones first
$offers = [];
if ($seller_id) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT o.offer_id, o.offered_price, o.counter_price, o.status, o.created_at,
                o.converted_order_id, p.product_name, p.price, p.stock_quantity,
                u.full_name AS customer_name
         FROM offers o
         JOIN products p ON p.product_id = o.product_id
         JOIN users u ON u.user_id = o.customer_id
         WHERE p.seller_id = ?
         ORDER BY FIELD(o.status, 'pending', 'countered', 'accepted', 'rejected'), o.created_at DESC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $seller_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $offers[] = $row;
    }
    mysqli_stmt_close($stmt);
}

$open_count = 0;
foreach ($offers as $o) {
    if ($o['status'] === 'pending') {
        $open_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Price Bidding — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/seller.css">
</head>

<body>
    <h1>Price Bidding</h1>

    <?php if ($successMsg): ?>
        <p class="success"><?php echo htmlspecialchars($successMsg); ?></p>
    <?php endif; ?>
    <?php if ($actionErr): ?>
        <p class="error"><?php echo $actionErr; ?></p>
    <?php endif; ?>
    <?php if ($counterErr): ?>
        <p class="error"><?php echo $counterErr; ?></p>
    <?php endif; ?>

    <?php if (!$seller_id): ?>
        <p class="error">No seller profile found for this account.</p>
    <?php elseif (empty($offers)): ?>
        <p>No customer has bid on your products yet.</p>
    <?php else: ?>
        <p class="notice"><?php echo $open_count; ?> bid(s) waiting on you.</p>

        <table border="1" cellpadding="8">
            <tr>
                <th>Product</th>
                <th>Listed</th>
                <th>Customer</th>
                <th>Offered</th>
                <th>Your Counter</th>
                <th>Difference</th>
                <th>Status</th>
                <th>Placed</th>
                <th>Action</th>
            </tr>
            <?php foreach ($offers as $o): ?>
                <?php
                $is_open = in_array($o['status'], ['pending', 'countered'], true) && $o['converted_order_id'] === null;
                $difference = $o['price'] - $o['offered_price'];
                $discount_pct = $o['price'] > 0 ? ($difference / $o['price']) * 100 : 0;
                ?>
                <tr class="<?php echo $o['status'] === 'pending' ? 'alert' : 'notice'; ?>">
                    <td><?php echo htmlspecialchars($o['product_name']); ?></td>
                    <td>৳<?php echo number_format($o['price'], 2); ?></td>
                    <td><?php echo htmlspecialchars($o['customer_name']); ?></td>
                    <td>৳<?php echo number_format($o['offered_price'], 2); ?></td>
                    <td>
                        <?php echo $o['counter_price'] !== null
                            ? '৳' . number_format($o['counter_price'], 2)
                            : '—'; ?>
                    </td>
                    <td>
                        ৳<?php echo number_format($difference, 2); ?>
                        (<?php echo number_format($discount_pct, 1); ?>% off)
                    </td>
                    <td>
                        <?php echo htmlspecialchars($o['status']); ?>
                        <?php if ($o['converted_order_id'] !== null): ?>
                            — ordered (#<?php echo $o['converted_order_id']; ?>)
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($o['created_at']); ?></td>
                    <td>
                        <?php if ($is_open): ?>
                            <form method="POST" action="price_bidding.php">
                                <input type="hidden" name="offer_id" value="<?php echo $o['offer_id']; ?>">
                                <button type="submit" name="action" value="accept">Accept</button>
                                <button type="submit" name="action" value="reject">Reject</button>
                                <input type="text" name="counter_price" size="6" placeholder="Counter ৳">
                                <button type="submit" name="action" value="counter">Counter</button>
                            </form>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <a href="products.php">My Products</a>
    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>
