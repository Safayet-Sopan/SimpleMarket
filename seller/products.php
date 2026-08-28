<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('seller');

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

$user_id = $_SESSION['user_id'];

// Get this seller's seller_id + approval status
/** @var mysqli $conn */
$stmt = mysqli_prepare($conn, "SELECT seller_id, approval_status FROM seller_profiles WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$seller = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
$seller_id = $seller['seller_id'] ?? null;

// Fetch categories for dropdown
$categories = [];
$result = mysqli_query($conn, "SELECT category_id, category_name FROM categories ORDER BY category_name");
while ($row = mysqli_fetch_assoc($result)) {
    $categories[] = $row;
}

$nameErr = $priceErr = $stockErr = $imageErr = "";
$successMsg = "";

// Editing an existing product?
$edit_id = $_GET['edit'] ?? '';
$editing_product = null;
if ($edit_id !== '' && ctype_digit($edit_id)) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM products WHERE product_id = ? AND seller_id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $edit_id, $seller_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $editing_product = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

$product_name = $editing_product['product_name'] ?? '';
$description = $editing_product['description'] ?? '';
$price = $editing_product['price'] ?? '';
$stock_quantity = $editing_product['stock_quantity'] ?? '';
$low_stock_threshold = $editing_product['low_stock_threshold'] ?? 5;
$category_id = $editing_product['category_id'] ?? '';
$existing_image = $editing_product['product_image'] ?? '';

// Handle deactivate / reactivate toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $toggle_id = (int) $_POST['toggle_status'];
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE products SET status = IF(status = 'active', 'inactive', 'active') WHERE product_id = ? AND seller_id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $toggle_id, $seller_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    header('Location: products.php');
    exit;
}

// Handle add / update submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_product'])) {
    $product_id = $_POST['product_id'] ?? '';

    // Name
    if (empty($_POST['product_name'])) {
        $nameErr = "Product name is required";
    } else {
        $product_name = cleanInput($_POST['product_name']);
    }

    $description = cleanInput($_POST['description'] ?? '');

    // Price
    if (empty($_POST['price'])) {
        $priceErr = "Price is required";
    } else {
        $price = $_POST['price'];
        if (!is_numeric($price) || $price <= 0) {
            $priceErr = "Price must be a positive number";
        }
    }

    // Stock quantity
    if ($_POST['stock_quantity'] === '') {
        $stockErr = "Stock quantity is required";
    } else {
        $stock_quantity = $_POST['stock_quantity'];
        if (!ctype_digit((string)$stock_quantity)) {
            $stockErr = "Stock must be a whole number";
        }
    }

    $low_stock_threshold = ctype_digit((string)($_POST['low_stock_threshold'] ?? '')) ? (int) $_POST['low_stock_threshold'] : 5;
    $category_id = ($_POST['category_id'] !== '') ? (int) $_POST['category_id'] : null;

    // Image upload (optional)
    $product_image = $existing_image;
    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
        $file_type = mime_content_type($_FILES['product_image']['tmp_name']);

        if (!in_array($file_type, $allowed_types, true)) {
            $imageErr = "Only JPG, PNG, or WEBP images are allowed";
        } elseif ($_FILES['product_image']['size'] > 2 * 1024 * 1024) {
            $imageErr = "Image must be under 2MB";
        } else {
            $ext = pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION);
            $new_filename = 'product_' . $seller_id . '_' . time() . '.' . $ext;
            $destination = '../uploads/products/' . $new_filename;

            if (move_uploaded_file($_FILES['product_image']['tmp_name'], $destination)) {
                $product_image = $new_filename;
            } else {
                $imageErr = "Failed to upload image";
            }
        }
    }

    if (!$nameErr && !$priceErr && !$stockErr && !$imageErr) {
        if ($product_id !== '' && ctype_digit($product_id)) {
            // Update existing product
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE products SET product_name=?, description=?, price=?, stock_quantity=?, low_stock_threshold=?, category_id=?, product_image=?
                 WHERE product_id=? AND seller_id=?"
            );
            mysqli_stmt_bind_param(
                $stmt,
                'ssdiiisii',
                $product_name,
                $description,
                $price,
                $stock_quantity,
                $low_stock_threshold,
                $category_id,
                $product_image,
                $product_id,
                $seller_id
            );
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $successMsg = "Product updated successfully.";
        } else {
            // Insert new product
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO products (seller_id, category_id, product_name, description, price, stock_quantity, low_stock_threshold, product_image, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')"
            );
            mysqli_stmt_bind_param(
                $stmt,
                'iissdiis',
                $seller_id,
                $category_id,
                $product_name,
                $description,
                $price,
                $stock_quantity,
                $low_stock_threshold,
                $product_image
            );
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $successMsg = "Product added successfully.";
        }

        // Reset form after successful save
        $product_name = $description = "";
        $price = $stock_quantity = "";
        $low_stock_threshold = 5;
        $category_id = "";
        $editing_product = null;
    }
}

// Fetch all of this seller's products
$products = [];
if ($seller_id) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM products WHERE seller_id = ? ORDER BY created_at DESC");
    mysqli_stmt_bind_param($stmt, 'i', $seller_id);
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
    <title>My Products — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/seller.css">
</head>

<body>
    <h1>My Products</h1>

    <?php if ($seller['approval_status'] !== 'approved'): ?>
        <p class="notice">Your shop must be approved before your products are visible to customers.</p>
    <?php endif; ?>

    <?php if ($successMsg): ?>
        <p class="success"><?php echo $successMsg; ?></p>
    <?php endif; ?>

    <h2><?php echo $editing_product ? 'Edit Product' : 'Add New Product'; ?></h2>

    <form method="POST" action="products.php" enctype="multipart/form-data">
        <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($editing_product['product_id'] ?? ''); ?>">

        <label>Product Name</label>
        <input type="text" name="product_name" value="<?php echo htmlspecialchars($product_name); ?>">
        <span class="error"><?php echo $nameErr; ?></span>

        <label>Description</label>
        <textarea name="description"><?php echo htmlspecialchars($description); ?></textarea>

        <label>Price (৳)</label>
        <input type="text" name="price" value="<?php echo htmlspecialchars($price); ?>">
        <span class="error"><?php echo $priceErr; ?></span>

        <label>Stock Quantity</label>
        <input type="text" name="stock_quantity" value="<?php echo htmlspecialchars($stock_quantity); ?>">
        <span class="error"><?php echo $stockErr; ?></span>

        <label>Low Stock Threshold</label>
        <input type="text" name="low_stock_threshold" value="<?php echo htmlspecialchars($low_stock_threshold); ?>">

        <label>Category</label>
        <select name="category_id">
            <option value="">Uncategorized</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat['category_id']; ?>" <?php echo ((string)$cat['category_id'] === (string)$category_id) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat['category_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Product Image</label>
        <input type="file" name="product_image" accept="image/jpeg,image/png,image/webp">
        <span class="error"><?php echo $imageErr; ?></span>
        <?php if ($existing_image): ?>
            <p>Current image: <?php echo htmlspecialchars($existing_image); ?></p>
        <?php endif; ?>

        <button type="submit" name="save_product" value="1"><?php echo $editing_product ? 'Update Product' : 'Add Product'; ?></button>
        <?php if ($editing_product): ?>
            <a href="products.php">Cancel Edit</a>
        <?php endif; ?>
    </form>

    <hr>

    <h2>All Products</h2>

    <?php if (empty($products)): ?>
        <p>You haven't added any products yet.</p>
    <?php else: ?>
        <table border="1" cellpadding="8">
            <tr>
                <th>Name</th>
                <th>Price</th>
                <th>Stock</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><?php echo htmlspecialchars($p['product_name']); ?></td>
                    <td>৳<?php echo number_format($p['price'], 2); ?></td>
                    <td><?php echo $p['stock_quantity']; ?><?php if ($p['stock_quantity'] <= $p['low_stock_threshold']): ?> <span class="alert">Low</span><?php endif; ?></td>
                    <td><?php echo htmlspecialchars($p['status']); ?></td>
                    <td>
                        <a href="products.php?edit=<?php echo $p['product_id']; ?>">Edit</a>
                        <form method="POST" action="products.php" style="display:inline;">
                            <input type="hidden" name="toggle_status" value="<?php echo $p['product_id']; ?>">
                            <button type="submit"><?php echo $p['status'] === 'active' ? 'Deactivate' : 'Reactivate'; ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>