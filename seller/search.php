<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('seller');

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

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

$keyword = cleanInput($_GET['keyword'] ?? '');
$search_type = $_GET['search_type'] ?? 'products'; // 'products' or 'orders'
$hasSearched = isset($_GET['keyword']) && $keyword !== '';

$products = [];
$orders = [];

if ($hasSearched && $seller_id) {
    if ($search_type === 'products') {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT product_id, product_name, price, stock_quantity, status
             FROM products WHERE seller_id = ? AND product_name LIKE ?
             ORDER BY product_name ASC"
        );
        $like = "%$keyword%";
        mysqli_stmt_bind_param($stmt, 'is', $seller_id, $like);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $products[] = $row;
        }
        mysqli_stmt_close($stmt);
    } else {
        // search own orders by order_id (numeric) or customer name
        $sql = "
            SELECT o.order_id, o.status, o.total_amount, o.created_at, u.full_name AS customer_name
            FROM orders o
            JOIN users u ON u.user_id = o.customer_id
            WHERE o.seller_id = ?
              AND (u.full_name LIKE ? OR o.order_id = ?)
            ORDER BY o.created_at DESC
        ";
        $like = "%$keyword%";
        $order_id_match = ctype_digit($keyword) ? (int) $keyword : 0;
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'isi', $seller_id, $like, $order_id_match);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $orders[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Search — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/seller.css">
</head>

<body>
    <h1>Search</h1>

    <form method="GET" action="search.php">
        <select name="search_type">
            <option value="products" <?php echo ($search_type === 'products') ? 'selected' : ''; ?>>My Products</option>
            <option value="orders" <?php echo ($search_type === 'orders') ? 'selected' : ''; ?>>My Orders</option>
        </select>
        <input type="text" name="keyword" placeholder="Search by name or order ID" value="<?php echo htmlspecialchars($keyword); ?>">
        <button type="submit">Search</button>
    </form>

    <?php if ($hasSearched && $search_type === 'products'): ?>
        <?php if (empty($products)): ?>
            <p>No products found.</p>
        <?php else: ?>
            <table border="1" cellpadding="8">
                <tr>
                    <th>Product</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Status</th>
                </tr>
                <?php foreach ($products as $p): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($p['product_name']); ?></td>
                        <td>৳<?php echo number_format($p['price'], 2); ?></td>
                        <td><?php echo $p['stock_quantity']; ?></td>
                        <td><?php echo htmlspecialchars($p['status']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    <?php elseif ($hasSearched && $search_type === 'orders'): ?>
        <?php if (empty($orders)): ?>
            <p>No orders found.</p>
        <?php else: ?>
            <table border="1" cellpadding="8">
                <tr>
                    <th>Order ID</th>
                    <th>Customer</th>
                    <th>Status</th>
                    <th>Total</th>
                    <th>Date</th>
                </tr>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td>#<?php echo $o['order_id']; ?></td>
                        <td><?php echo htmlspecialchars($o['customer_name']); ?></td>
                        <td><?php echo htmlspecialchars($o['status']); ?></td>
                        <td>৳<?php echo number_format($o['total_amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($o['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>