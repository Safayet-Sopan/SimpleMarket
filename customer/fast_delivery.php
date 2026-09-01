<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../config.php';
require_role('customer');

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

$customer_id = $_SESSION['user_id'];
$product_id = $_GET['product_id'] ?? $_POST['product_id'] ?? '';
$offer_id = $_GET['offer_id'] ?? $_POST['offer_id'] ?? '';

$quantityErr = $addressErr = $stockErr = "";
$successMsg = "";

if (!ctype_digit((string)$product_id)) {
    die('Invalid product.');
}
$product_id = (int) $product_id;

// Fetch product + seller info
/** @var mysqli $conn */
$stmt = mysqli_prepare(
    $conn,
    "SELECT p.product_id, p.product_name, p.price, p.stock_quantity, p.seller_id, sp.shop_name, sp.commission_rate, sp.approval_status, sp.payment_methods
     FROM products p
     JOIN seller_profiles sp ON sp.seller_id = p.seller_id
     WHERE p.product_id = ? AND p.status = 'active'"
);
mysqli_stmt_bind_param($stmt, 'i', $product_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$product = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$product || $product['approval_status'] !== 'approved') {
    die('This product is not available.');
}

// An accepted bid lets this customer buy below the listed price. The bid must be
// theirs, for this product, accepted, and not already spent on another order.
$offer = null;
$offerNotice = "";
$unit_price = (float) $product['price'];

if (ctype_digit((string)$offer_id)) {
    $offer_id = (int) $offer_id;
    $stmt = mysqli_prepare(
        $conn,
        "SELECT offer_id, offered_price, counter_price, status
         FROM offers
         WHERE offer_id = ? AND customer_id = ? AND product_id = ?
           AND status = 'accepted' AND converted_order_id IS NULL"
    );
    mysqli_stmt_bind_param($stmt, 'iii', $offer_id, $customer_id, $product_id);
    mysqli_stmt_execute($stmt);
    $offer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($offer) {
        // A counter-offer is the agreed price when the seller made one
        $unit_price = (float) ($offer['counter_price'] !== null
            ? $offer['counter_price']
            : $offer['offered_price']);
    } else {
        // The bid was spent, withdrawn or revoked between the click and here.
        // Say so rather than quietly charging the full listed price.
        $offer_id = 0;
        $offerNotice = "That accepted bid is no longer valid, so this order is priced at the listed price.";
    }
} else {
    $offer_id = 0;
}

// Only the methods this shop actually accepts are offered at checkout
$shop_methods = [];
foreach (explode(',', $product['payment_methods']) as $key) {
    $key = trim($key);
    if (array_key_exists($key, $PAYMENT_METHODS)) {
        $shop_methods[$key] = $PAYMENT_METHODS[$key];
    }
}
if (empty($shop_methods)) {
    // Shop has not configured anything — fall back to the universal option
    $shop_methods = ['cod' => $PAYMENT_METHODS['cod']];
}

$quantity = 1;
$delivery_address = "";
$fast_delivery = 0;
$payment_method = "";
$paymentErr = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Quantity
    $quantity = $_POST['quantity'] ?? '';
    if (!ctype_digit((string)$quantity) || (int)$quantity < 1) {
        $quantityErr = "Enter a valid quantity";
    } else {
        $quantity = (int) $quantity;
        if ($quantity > $product['stock_quantity']) {
            $stockErr = "Only {$product['stock_quantity']} in stock";
        }
    }

    // Delivery address
    if (empty($_POST['delivery_address'])) {
        $addressErr = "Delivery address is required";
    } else {
        $delivery_address = cleanInput($_POST['delivery_address']);
    }

    $fast_delivery = isset($_POST['fast_delivery']) ? 1 : 0;

    // Payment method
    $payment_method = $_POST['payment_method'] ?? '';
    if ($payment_method === '') {
        $paymentErr = "Choose a payment method";
    } elseif (!array_key_exists($payment_method, $shop_methods)) {
        $paymentErr = "This shop does not accept that payment method";
    }

    if (!$quantityErr && !$addressErr && !$stockErr && !$paymentErr) {
        $subtotal = $unit_price * $quantity;
        $delivery_fee = $fast_delivery ? FAST_DELIVERY_FEE : STANDARD_DELIVERY_FEE;
        $commission_amount = round($subtotal * ($product['commission_rate'] / 100), 2);
        $total_amount = $subtotal + $delivery_fee;

        mysqli_begin_transaction($conn);
        try {
            // Re-check stock inside the transaction to prevent overselling on concurrent orders
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE products SET stock_quantity = stock_quantity - ?
                 WHERE product_id = ? AND stock_quantity >= ?"
            );
            mysqli_stmt_bind_param($stmt, 'iii', $quantity, $product_id, $quantity);
            mysqli_stmt_execute($stmt);
            $stock_updated = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);

            if ($stock_updated === 0) {
                throw new Exception('Stock unavailable');
            }

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO orders (customer_id, seller_id, delivery_address, fast_delivery, delivery_fee, subtotal, commission_amount, total_amount, payment_method, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            mysqli_stmt_bind_param(
                $stmt,
                'iisidddds',
                $customer_id,
                $product['seller_id'],
                $delivery_address,
                $fast_delivery,
                $delivery_fee,
                $subtotal,
                $commission_amount,
                $total_amount,
                $payment_method
            );
            mysqli_stmt_execute($stmt);
            $order_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO order_items (order_id, product_id, quantity, unit_price, line_total)
                 VALUES (?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, 'iiidd', $order_id, $product_id, $quantity, $unit_price, $subtotal);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // Spend the bid. The converted_order_id IS NULL guard is what makes this
            // safe under concurrent submits — a second attempt updates zero rows.
            if ($offer_id) {
                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE offers SET converted_order_id = ?
                     WHERE offer_id = ? AND customer_id = ? AND converted_order_id IS NULL"
                );
                mysqli_stmt_bind_param($stmt, 'iii', $order_id, $offer_id, $customer_id);
                mysqli_stmt_execute($stmt);
                $offer_spent = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);

                if ($offer_spent === 0) {
                    throw new Exception('Offer already used');
                }
            }

            // Notify the seller
            $notif_message = "New order #{$order_id} received for {$product['product_name']} (x{$quantity}).";
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO notifications (user_id, message)
                 SELECT user_id, ? FROM seller_profiles WHERE seller_id = ?"
            );
            mysqli_stmt_bind_param($stmt, 'si', $notif_message, $product['seller_id']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            mysqli_commit($conn);

            header('Location: order_tracking.php?order_id=' . $order_id);
            exit;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $stockErr = "Something went wrong — order was not placed. Please try again.";
        }
    }
}

$subtotal_preview = $unit_price * max(1, (int)$quantity);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Place Order — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/customer.css">
</head>

<body>
    <h1>Place Order</h1>

    <h2><?php echo htmlspecialchars($product['product_name']); ?></h2>
    <p>Sold by <?php echo htmlspecialchars($product['shop_name']); ?></p>
    <p>Price: ৳<?php echo number_format($product['price'], 2); ?> | In stock: <?php echo $product['stock_quantity']; ?></p>

    <?php if ($offerNotice): ?>
        <p class="alert"><?php echo htmlspecialchars($offerNotice); ?></p>
    <?php endif; ?>

    <?php if ($offer_id): ?>
        <p class="success">Accepted bid applied — you pay
            ৳<?php echo number_format($unit_price, 2); ?> per unit instead of
            ৳<?php echo number_format($product['price'], 2); ?>.</p>
    <?php endif; ?>

    <?php if ($stockErr): ?>
        <p class="error"><?php echo $stockErr; ?></p>
    <?php endif; ?>

    <form method="POST" action="fast_delivery.php">
        <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">
        <?php if ($offer_id): ?>
            <input type="hidden" name="offer_id" value="<?php echo $offer_id; ?>">
        <?php endif; ?>

        <label>Quantity</label>
        <input type="text" name="quantity" value="<?php echo htmlspecialchars($quantity); ?>">
        <span class="error"><?php echo $quantityErr; ?></span>

        <label>Delivery Address</label>
        <input type="text" name="delivery_address" value="<?php echo htmlspecialchars($delivery_address); ?>">
        <span class="error"><?php echo $addressErr; ?></span>

        <label>Payment Method</label>
        <select name="payment_method">
            <option value="">-- Select --</option>
            <?php foreach ($shop_methods as $key => $label): ?>
                <option value="<?php echo htmlspecialchars($key); ?>"
                    <?php echo $payment_method === $key ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span class="error"><?php echo $paymentErr; ?></span>

        <label>
            <input type="checkbox" name="fast_delivery" value="1" <?php echo $fast_delivery ? 'checked' : ''; ?>>
            Fast Delivery (+৳<?php echo number_format(FAST_DELIVERY_FEE, 2); ?> instead of ৳<?php echo number_format(STANDARD_DELIVERY_FEE, 2); ?>)
        </label>

        <p>Estimated subtotal: ৳<?php echo number_format($subtotal_preview, 2); ?> (delivery fee added at checkout)</p>

        <button type="submit">Place Order</button>
    </form>

    <a href="search.php">Back to Search</a>
</body>

</html>