<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('rider');

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

$user_id = $_SESSION['user_id'];

// Get this rider's rider_id
/** @var mysqli $conn */
$stmt = mysqli_prepare($conn, "SELECT password_hash FROM users WHERE user_id = ?");
$stmt = mysqli_prepare($conn, "SELECT rider_id FROM rider_profiles WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$rider = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
$rider_id = $rider['rider_id'] ?? null;

$keyword = cleanInput($_GET['keyword'] ?? '');
$hasSearched = isset($_GET['keyword']) && $keyword !== '';

$orders = [];

if ($hasSearched && $rider_id) {
    $like = "%$keyword%";
    $order_id_match = ctype_digit($keyword) ? (int) $keyword : 0;

    $stmt = mysqli_prepare(
        $conn,
        "SELECT order_id, delivery_address, status, total_amount, created_at
         FROM orders
         WHERE rider_id = ? AND (delivery_address LIKE ? OR order_id = ?)
         ORDER BY created_at DESC"
    );
    mysqli_stmt_bind_param($stmt, 'isi', $rider_id, $like, $order_id_match);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $orders[] = $row;
    }
    mysqli_stmt_close($stmt);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Search — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/rider.css">
</head>

<body>
    <h1>Search My Deliveries</h1>

    <form method="GET" action="search.php">
        <input type="text" name="keyword" placeholder="Search by order ID or address" value="<?php echo htmlspecialchars($keyword); ?>">
        <button type="submit">Search</button>
    </form>

    <?php if ($hasSearched): ?>
        <?php if (empty($orders)): ?>
            <p>No matching deliveries found.</p>
        <?php else: ?>
            <table border="1" cellpadding="8">
                <tr>
                    <th>Order ID</th>
                    <th>Address</th>
                    <th>Status</th>
                    <th>Total</th>
                    <th>Date</th>
                </tr>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td>#<?php echo $o['order_id']; ?></td>
                        <td><?php echo htmlspecialchars($o['delivery_address']); ?></td>
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