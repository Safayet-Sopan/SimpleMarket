<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('seller');

$user_id = $_SESSION['user_id'];

// Get this seller's seller_id
/** @var mysqli $conn */
$stmt = mysqli_prepare($conn, "SELECT seller_id FROM seller_profiles WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$seller = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
$seller_id = $seller['seller_id'] ?? null;

// Fetch products at or below their low stock threshold
$low_stock_products = [];
if ($seller_id) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT product_id, product_name, stock_quantity, low_stock_threshold, status
         FROM products
         WHERE seller_id = ? AND stock_quantity <= low_stock_threshold
         ORDER BY stock_quantity ASC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $seller_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $low_stock_products[] = $row;
    }
    mysqli_stmt_close($stmt);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Low Stock Alert — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/seller.css">
</head>

<body>
    <h1>Low Stock Alert</h1>

    <?php if (empty($low_stock_products)): ?>
        <p>All your products are sufficiently stocked. Nothing to worry about right now.</p>
    <?php else: ?>
        <p class="notice"><?php echo count($low_stock_products); ?> product(s) need restocking.</p>

        <table border="1" cellpadding="8">
            <tr>
                <th>Product</th>
                <th>Current Stock</th>
                <th>Threshold</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
            <?php foreach ($low_stock_products as $p): ?>
                <tr class="<?php echo $p['stock_quantity'] == 0 ? 'critical' : 'alert'; ?>">
                    <td><?php echo htmlspecialchars($p['product_name']); ?></td>
                    <td><?php echo $p['stock_quantity']; ?></td>
                    <td><?php echo $p['low_stock_threshold']; ?></td>
                    <td><?php echo $p['stock_quantity'] == 0 ? 'Out of stock' : 'Low stock'; ?></td>
                    <td><a href="products.php?edit=<?php echo $p['product_id']; ?>">Restock</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>