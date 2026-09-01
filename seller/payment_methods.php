<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../config.php';
require_role('seller');

/** @var mysqli $conn */

$user_id = $_SESSION['user_id'];

$stmt = mysqli_prepare(
    $conn,
    "SELECT seller_id, shop_name, payment_methods FROM seller_profiles WHERE user_id = ?"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$seller = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
$seller_id = $seller['seller_id'] ?? null;

$methodsErr = "";
$successMsg = "";

// Stored as a comma-separated list of keys from $PAYMENT_METHODS
$selected = array_filter(explode(',', $seller['payment_methods'] ?? 'cod'));

if ($_SERVER["REQUEST_METHOD"] == "POST" && $seller_id) {
    $posted = $_POST['methods'] ?? [];

    if (!is_array($posted)) {
        $posted = [];
    }

    // Keep only keys we actually recognise — never trust the posted list
    $clean = [];
    foreach ($posted as $key) {
        if (array_key_exists($key, $PAYMENT_METHODS) && !in_array($key, $clean, true)) {
            $clean[] = $key;
        }
    }

    if (empty($clean)) {
        $methodsErr = "Pick at least one payment method — customers cannot check out otherwise.";
    } else {
        $methods_value = implode(',', $clean);

        $stmt = mysqli_prepare($conn, "UPDATE seller_profiles SET payment_methods = ? WHERE seller_id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $methods_value, $seller_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $selected = $clean;
        $successMsg = "Payment methods updated. Customers will see these at checkout.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Payment Methods — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/seller.css">
</head>

<body>
    <h1>Payment Methods</h1>

    <?php if ($successMsg): ?>
        <p class="success"><?php echo htmlspecialchars($successMsg); ?></p>
    <?php endif; ?>

    <?php if (!$seller_id): ?>
        <p class="error">No seller profile found for this account.</p>
    <?php else: ?>
        <p>Choose what <?php echo htmlspecialchars($seller['shop_name']); ?> accepts.
            The customer picks one of these at checkout, then you confirm the money
            arrived from your <a href="orders.php">Orders</a> page.</p>

        <p class="notice">There is no payment gateway. Every method here is settled by
            hand between you and the customer.</p>

        <form method="POST" action="payment_methods.php">
            <?php foreach ($PAYMENT_METHODS as $key => $label): ?>
                <label>
                    <input type="checkbox" name="methods[]" value="<?php echo htmlspecialchars($key); ?>"
                        <?php echo in_array($key, $selected, true) ? 'checked' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </label><br>
            <?php endforeach; ?>

            <span class="error"><?php echo $methodsErr; ?></span><br>

            <button type="submit">Save</button>
        </form>
    <?php endif; ?>

    <a href="orders.php">Orders</a>
    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>
