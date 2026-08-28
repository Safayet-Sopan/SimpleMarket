<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('customer');

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

// Delivery fee constants — flat rates, no external distance/maps API
define('STANDARD_DELIVERY_FEE', 30.00);
define('FAST_DELIVERY_FEE', 70.00);

$customer_id = $_SESSION['user_id'];
$product_id = $_GET['product_id'] ?? $_POST['product_id'] ?? '';

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
    "SELECT p.product_id, p.product_name, p.price, p.stock_quantity, p.seller_id, sp.shop_name, sp.commission_rate, sp.approval_status
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

$quantity = 1;
$delivery_address = "";
$fast_delivery = 0;

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

    if (!$quantityErr && !$addressErr && !$stockErr) {
        $subtotal = $product['price'] * $quantity;
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
                "INSERT INTO orders (customer_id, seller_id, delivery_address, fast_delivery, delivery_fee, subtotal, commission_amount, total_amount, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            mysqli_stmt_bind_param(
                $stmt,
                'iisiddd',
                $customer_id,
                $product['seller_id'],
                $delivery_address,
                $fast_delivery,
                $delivery_fee,
                $subtotal,
                $commission_amount,
                $total_amount
            );
            mysqli_stmt_execute($stmt);
            $order_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO order_items (order_id, product_id, quantity, unit_price, line_total)
                 VALUES (?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, 'iiidd', $order_id, $product_id, $quantity, $product['price'], $subtotal);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

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

$subtotal_preview = $product['price'] * max(1, (int)$quantity);
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

    <?php if ($stockErr): ?>
        <p class="error"><?php echo $stockErr; ?></p>
    <?php endif; ?>

    <form method="POST" action="fast_delivery.php">
        <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">

        <label>Quantity</label>
        <input type="text" name="quantity" value="<?php echo htmlspecialchars($quantity); ?>">
        <span class="error"><?php echo $quantityErr; ?></span>

        <label>Delivery Address</label>
        <input type="text" name="delivery_address" value="<?php echo htmlspecialchars($delivery_address); ?>">
        <span class="error"><?php echo $addressErr; ?></span>

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