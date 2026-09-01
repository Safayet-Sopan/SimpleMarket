<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('customer');

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

/** @var mysqli $conn */

$customer_id = $_SESSION['user_id'];
$product_id = $_GET['product_id'] ?? $_POST['product_id'] ?? '';

$priceErr = $actionErr = "";
$successMsg = "";
$offered_price = "";
$product = null;

// A product_id is optional — without one this page is just the customer's bid list
if (ctype_digit((string)$product_id)) {
    $product_id = (int) $product_id;
    $stmt = mysqli_prepare(
        $conn,
        "SELECT p.product_id, p.product_name, p.price, p.stock_quantity, sp.shop_name
         FROM products p
         JOIN seller_profiles sp ON sp.seller_id = p.seller_id
         WHERE p.product_id = ? AND p.status = 'active' AND sp.approval_status = 'approved'"
    );
    mysqli_stmt_bind_param($stmt, 'i', $product_id);
    mysqli_stmt_execute($stmt);
    $product = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
} else {
    $product_id = 0;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST["action"] ?? 'bid';

    if ($action === 'bid') {
        // Customer places a new bid on a product
        if (!$product) {
            $actionErr = "This product is not available.";
        } else {
            $offered_price = $_POST["offered_price"] ?? '';

            if ($offered_price === '') {
                $priceErr = "Enter the price you want to offer";
            } elseif (!is_numeric($offered_price)) {
                $priceErr = "Offer must be a number";
            } elseif ((float)$offered_price <= 0) {
                $priceErr = "Offer must be greater than 0";
            } elseif ((float)$offered_price >= (float)$product['price']) {
                $priceErr = "Offer must be below the listed price of ৳" . number_format($product['price'], 2);
            }

            if (!$priceErr) {
                // One open bid per product at a time, so sellers do not see duplicates
                $stmt = mysqli_prepare(
                    $conn,
                    "SELECT offer_id FROM offers
                     WHERE product_id = ? AND customer_id = ?
                       AND status IN ('pending','countered') AND converted_order_id IS NULL"
                );
                mysqli_stmt_bind_param($stmt, 'ii', $product_id, $customer_id);
                mysqli_stmt_execute($stmt);
                $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);

                if ($existing) {
                    $actionErr = "You already have an open bid on this product.";
                } else {
                    $offered_price_value = round((float)$offered_price, 2);

                    mysqli_begin_transaction($conn);
                    try {
                        $stmt = mysqli_prepare(
                            $conn,
                            "INSERT INTO offers (product_id, customer_id, offered_price, status)
                             VALUES (?, ?, ?, 'pending')"
                        );
                        mysqli_stmt_bind_param($stmt, 'iid', $product_id, $customer_id, $offered_price_value);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);

                        $notif_message = "New bid of ৳" . number_format($offered_price_value, 2)
                            . " on '" . $product['product_name'] . "'.";
                        $stmt = mysqli_prepare(
                            $conn,
                            "INSERT INTO notifications (user_id, message)
                             SELECT sp.user_id, ? FROM seller_profiles sp
                             JOIN products p ON p.seller_id = sp.seller_id
                             WHERE p.product_id = ?"
                        );
                        mysqli_stmt_bind_param($stmt, 'si', $notif_message, $product_id);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);

                        mysqli_commit($conn);
                        $successMsg = "Bid sent. You will be notified when the seller responds.";
                        $offered_price = "";
                    } catch (Exception $e) {
                        mysqli_rollback($conn);
                        $actionErr = "Something went wrong. Your bid was not sent.";
                    }
                }
            }
        }

    } elseif (in_array($action, ['accept_counter', 'withdraw'], true)) {
        // Customer responds to a seller's counter-offer
        $offer_id = $_POST["offer_id"] ?? '';

        if (!ctype_digit((string)$offer_id)) {
            $actionErr = "Invalid request.";
        } else {
            $offer_id = (int) $offer_id;

            $stmt = mysqli_prepare(
                $conn,
                "SELECT o.offer_id, o.status, o.counter_price, o.converted_order_id, p.product_name, p.seller_id
                 FROM offers o
                 JOIN products p ON p.product_id = o.product_id
                 WHERE o.offer_id = ? AND o.customer_id = ?"
            );
            mysqli_stmt_bind_param($stmt, 'ii', $offer_id, $customer_id);
            mysqli_stmt_execute($stmt);
            $offer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if (!$offer) {
                $actionErr = "Bid not found.";
            } elseif ($offer['converted_order_id'] !== null) {
                $actionErr = "That bid has already been turned into an order.";
            } elseif ($action === 'accept_counter' && $offer['status'] !== 'countered') {
                $actionErr = "There is no counter-offer to accept.";
            } elseif ($action === 'withdraw' && !in_array($offer['status'], ['pending', 'countered'], true)) {
                $actionErr = "That bid is already settled.";
            } else {
                $new_status = ($action === 'accept_counter') ? 'accepted' : 'rejected';
                $notif_message = ($action === 'accept_counter')
                    ? "The customer accepted your counter of ৳" . number_format($offer['counter_price'], 2)
                        . " for '" . $offer['product_name'] . "'."
                    : "A customer withdrew their bid on '" . $offer['product_name'] . "'.";

                mysqli_begin_transaction($conn);
                try {
                    $stmt = mysqli_prepare($conn, "UPDATE offers SET status = ? WHERE offer_id = ?");
                    mysqli_stmt_bind_param($stmt, 'si', $new_status, $offer_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    $stmt = mysqli_prepare(
                        $conn,
                        "INSERT INTO notifications (user_id, message)
                         SELECT user_id, ? FROM seller_profiles WHERE seller_id = ?"
                    );
                    mysqli_stmt_bind_param($stmt, 'si', $notif_message, $offer['seller_id']);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    mysqli_commit($conn);
                    $successMsg = ($action === 'accept_counter')
                        ? "Counter-offer accepted. You can now order at that price."
                        : "Bid withdrawn.";
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $actionErr = "Something went wrong. Please try again.";
                }
            }
        }
    }
}

// This customer's bids, open ones first
$my_offers = [];
$stmt = mysqli_prepare(
    $conn,
    "SELECT o.offer_id, o.product_id, o.offered_price, o.counter_price, o.status,
            o.created_at, o.converted_order_id,
            p.product_name, p.price, p.stock_quantity, p.status AS product_status,
            sp.shop_name
     FROM offers o
     JOIN products p ON p.product_id = o.product_id
     JOIN seller_profiles sp ON sp.seller_id = p.seller_id
     WHERE o.customer_id = ?
     ORDER BY FIELD(o.status, 'countered', 'accepted', 'pending', 'rejected'), o.created_at DESC"
);
mysqli_stmt_bind_param($stmt, 'i', $customer_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $my_offers[] = $row;
}
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Bids — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/customer.css">
</head>

<body>
    <h1>Price Bidding</h1>

    <?php if ($successMsg): ?>
        <p class="success"><?php echo htmlspecialchars($successMsg); ?></p>
    <?php endif; ?>
    <?php if ($actionErr): ?>
        <p class="error"><?php echo $actionErr; ?></p>
    <?php endif; ?>

    <?php if ($product): ?>
        <h2>Bid on <?php echo htmlspecialchars($product['product_name']); ?></h2>
        <p>Sold by <?php echo htmlspecialchars($product['shop_name']); ?></p>
        <p>Listed price: ৳<?php echo number_format($product['price'], 2); ?>
            | In stock: <?php echo $product['stock_quantity']; ?></p>

        <form method="POST" action="make_offer.php">
            <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
            <input type="hidden" name="action" value="bid">

            <label>Your offer ৳</label>
            <input type="text" name="offered_price" value="<?php echo htmlspecialchars($offered_price); ?>">
            <span class="error"><?php echo $priceErr; ?></span>

            <button type="submit">Send Bid</button>
        </form>
    <?php elseif ($product_id): ?>
        <p class="error">This product is not available for bidding.</p>
    <?php endif; ?>

    <h2>My Bids</h2>

    <?php if (empty($my_offers)): ?>
        <p>You have not placed any bids yet. Find something on the
            <a href="search.php">search page</a> and make an offer.</p>
    <?php else: ?>
        <table border="1" cellpadding="8">
            <tr>
                <th>Product</th>
                <th>Shop</th>
                <th>Listed</th>
                <th>You Offered</th>
                <th>Seller Countered</th>
                <th>Status</th>
                <th>Placed</th>
                <th>Action</th>
            </tr>
            <?php foreach ($my_offers as $o): ?>
                <?php
                $agreed_price = $o['counter_price'] !== null ? $o['counter_price'] : $o['offered_price'];
                $can_order = $o['status'] === 'accepted'
                    && $o['converted_order_id'] === null
                    && $o['product_status'] === 'active'
                    && $o['stock_quantity'] > 0;
                ?>
                <tr class="<?php echo $o['status'] === 'countered' ? 'alert' : 'notice'; ?>">
                    <td><?php echo htmlspecialchars($o['product_name']); ?></td>
                    <td><?php echo htmlspecialchars($o['shop_name']); ?></td>
                    <td>৳<?php echo number_format($o['price'], 2); ?></td>
                    <td>৳<?php echo number_format($o['offered_price'], 2); ?></td>
                    <td><?php echo $o['counter_price'] !== null
                            ? '৳' . number_format($o['counter_price'], 2)
                            : '—'; ?></td>
                    <td>
                        <?php echo htmlspecialchars($o['status']); ?>
                        <?php if ($o['converted_order_id'] !== null): ?>
                            — ordered (#<?php echo $o['converted_order_id']; ?>)
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($o['created_at']); ?></td>
                    <td>
                        <?php if ($can_order): ?>
                            <a href="fast_delivery.php?product_id=<?php echo $o['product_id']; ?>&offer_id=<?php echo $o['offer_id']; ?>">
                                Order at ৳<?php echo number_format($agreed_price, 2); ?>
                            </a>
                        <?php elseif ($o['status'] === 'accepted' && $o['converted_order_id'] === null): ?>
                            Out of stock
                        <?php elseif ($o['status'] === 'countered'): ?>
                            <form method="POST" action="make_offer.php">
                                <input type="hidden" name="offer_id" value="<?php echo $o['offer_id']; ?>">
                                <button type="submit" name="action" value="accept_counter">
                                    Accept ৳<?php echo number_format($o['counter_price'], 2); ?>
                                </button>
                                <button type="submit" name="action" value="withdraw">Withdraw</button>
                            </form>
                        <?php elseif ($o['status'] === 'pending'): ?>
                            <form method="POST" action="make_offer.php">
                                <input type="hidden" name="offer_id" value="<?php echo $o['offer_id']; ?>">
                                <button type="submit" name="action" value="withdraw">Withdraw</button>
                            </form>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <a href="search.php">Back to Search</a>
    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>
