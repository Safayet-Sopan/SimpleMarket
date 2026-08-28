<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('admin');

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

$keyword = cleanInput($_GET['keyword'] ?? '');
$hasSearched = isset($_GET['keyword']) && $keyword !== '';

$results = [];

if ($hasSearched) {
    $like = "%$keyword%";
    /** @var mysqli $conn */
    $stmt = mysqli_prepare($conn, "SELECT password_hash FROM users WHERE user_id = ?");
    $stmt = mysqli_prepare(
        $conn,
        "SELECT u.user_id, u.full_name, u.email, u.role, u.status, sp.shop_name
         FROM users u
         LEFT JOIN seller_profiles sp ON sp.user_id = u.user_id
         WHERE u.full_name LIKE ? OR u.email LIKE ? OR sp.shop_name LIKE ?
         ORDER BY u.full_name ASC"
    );
    mysqli_stmt_bind_param($stmt, 'sss', $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $results[] = $row;
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
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body>
    <h1>Search Users</h1>

    <form method="GET" action="search.php">
        <input type="text" name="keyword" placeholder="Search by name, email, or shop name" value="<?php echo htmlspecialchars($keyword); ?>">
        <button type="submit">Search</button>
    </form>

    <?php if ($hasSearched): ?>
        <?php if (empty($results)): ?>
            <p>No results found.</p>
        <?php else: ?>
            <table border="1" cellpadding="8">
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Shop</th>
                    <th>Status</th>
                </tr>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['email']); ?></td>
                        <td><?php echo htmlspecialchars($r['role']); ?></td>
                        <td><?php echo htmlspecialchars($r['shop_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($r['status']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>