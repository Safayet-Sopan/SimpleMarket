<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('customer');

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

$keyword = cleanInput($_GET['keyword'] ?? '');
$category_id = $_GET['category_id'] ?? '';

// Fetch all categories for the filter dropdown
$categories = [];
/** @var mysqli $conn */
$result = mysqli_query($conn, "SELECT category_id, category_name FROM categories ORDER BY category_name");
while ($row = mysqli_fetch_assoc($result)) {
    $categories[] = $row;
}

$products = [];
$hasSearched = ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['keyword']) || isset($_GET['category_id'])));

if ($hasSearched) {
    $sql = "
        SELECT p.product_id, p.product_name, p.description, p.price, p.stock_quantity, p.product_image,
               sp.shop_name, c.category_name
        FROM products p
        JOIN seller_profiles sp ON sp.seller_id = p.seller_id
        LEFT JOIN categories c ON c.category_id = p.category_id
        WHERE p.status = 'active'
          AND sp.approval_status = 'approved'
    ";
    $params = [];
    $types = "";

    if ($keyword !== '') {
        $sql .= " AND p.product_name LIKE ?";
        $params[] = "%$keyword%";
        $types .= "s";
    }

    if ($category_id !== '' && ctype_digit($category_id)) {
        $sql .= " AND p.category_id = ?";
        $params[] = (int) $category_id;
        $types .= "i";
    }

    $sql .= " ORDER BY p.product_name ASC";

    $stmt = mysqli_prepare($conn, $sql);
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $products[] = $row;
    }
    mysqli_stmt_close($stmt);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Search Products — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/customer.css">
</head>

<body>
    <h1>Search Products</h1>

    <form method="GET" action="search.php">
        <input type="text" name="keyword" placeholder="Search by product name" value="<?php echo htmlspecialchars($keyword); ?>">

        <select name="category_id">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat['category_id']; ?>" <?php echo ((string)$cat['category_id'] === (string)$category_id) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat['category_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit">Search</button>
    </form>

    <?php if ($hasSearched): ?>
        <?php if (empty($products)): ?>
            <p>No products found.</p>
        <?php else: ?>
            <table border="1" cellpadding="8">
                <tr>
                    <th>Product</th>
                    <th>Shop</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Action</th>
                </tr>
                <?php foreach ($products as $p): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($p['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($p['shop_name']); ?></td>
                        <td><?php echo htmlspecialchars($p['category_name'] ?? 'Uncategorized'); ?></td>
                        <td>৳<?php echo number_format($p['price'], 2); ?></td>
                        <td><?php echo $p['stock_quantity'] > 0 ? $p['stock_quantity'] : 'Out of stock'; ?></td>
                        <td>
                            <?php if ($p['stock_quantity'] > 0): ?>
                                <a href="fast_delivery.php?product_id=<?php echo $p['product_id']; ?>">Order Now</a>
                                <a href="make_offer.php?product_id=<?php echo $p['product_id']; ?>">Make an Offer</a>
                            <?php else: ?>
                                Unavailable
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>