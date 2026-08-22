<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('admin');

$actionErr = "";
$successMsg = "";

// Handle approve/reject action
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $seller_id = $_POST["seller_id"] ?? '';
    $action = $_POST["action"] ?? '';

    if (empty($seller_id) || !in_array($action, ['approve', 'reject'], true)) {
        $actionErr = "Invalid request.";
    } else {
        $seller_id = (int) $seller_id;

        // Get the user_id + shop_name tied to this seller_id, to update users table + send notification
        $stmt = mysqli_prepare($conn, "SELECT user_id, shop_name FROM seller_profiles WHERE seller_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $seller_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $seller = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if (!$seller) {
            $actionErr = "Seller not found.";
        } else {
            $new_approval_status = ($action === 'approve') ? 'approved' : 'rejected';
            $new_user_status = ($action === 'approve') ? 'active' : 'suspended';
            $notif_message = ($action === 'approve')
                ? "Your shop '{$seller['shop_name']}' has been approved. You can now log in."
                : "Your shop '{$seller['shop_name']}' application was rejected.";

            mysqli_begin_transaction($conn);
            try {
                $stmt = mysqli_prepare($conn, "UPDATE seller_profiles SET approval_status = ? WHERE seller_id = ?");
                mysqli_stmt_bind_param($stmt, 'si', $new_approval_status, $seller_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $stmt = mysqli_prepare($conn, "UPDATE users SET status = ? WHERE user_id = ?");
                mysqli_stmt_bind_param($stmt, 'si', $new_user_status, $seller['user_id']);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                mysqli_stmt_bind_param($stmt, 'is', $seller['user_id'], $notif_message);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                mysqli_commit($conn);
                $successMsg = "Seller " . ($action === 'approve' ? 'approved' : 'rejected') . " successfully.";
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $actionErr = "Something went wrong. Please try again.";
            }
        }
    }
}

// Fetch all pending applications, sortable by applied_at
$sort = $_GET['sort'] ?? 'newest';
$order_by = ($sort === 'oldest') ? 'sp.applied_at ASC' : 'sp.applied_at DESC';

$pending = [];
$result = mysqli_query($conn, "
    SELECT sp.seller_id, sp.shop_name, sp.shop_address, sp.business_type, sp.applied_at,
           u.full_name, u.email, u.phone
    FROM seller_profiles sp
    JOIN users u ON u.user_id = sp.user_id
    WHERE sp.approval_status = 'pending'
    ORDER BY $order_by
");
while ($row = mysqli_fetch_assoc($result)) {
    $pending[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Seller Approvals — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body>
    <h1>Seller Approvals</h1>

    <?php if ($successMsg): ?>
        <p class="success"><?php echo $successMsg; ?></p>
    <?php endif; ?>
    <?php if ($actionErr): ?>
        <p class="error"><?php echo $actionErr; ?></p>
    <?php endif; ?>

    <p>
        Sort by:
        <a href="seller_approvals.php?sort=newest">Newest First</a> |
        <a href="seller_approvals.php?sort=oldest">Oldest First</a>
    </p>

    <?php if (empty($pending)): ?>
        <p>No pending seller applications.</p>
    <?php else: ?>
        <table border="1" cellpadding="8">
            <tr>
                <th>Shop Name</th>
                <th>Owner</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Address</th>
                <th>Business Type</th>
                <th>Applied</th>
                <th>Action</th>
            </tr>
            <?php foreach ($pending as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['shop_name']); ?></td>
                    <td><?php echo htmlspecialchars($s['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($s['email']); ?></td>
                    <td><?php echo htmlspecialchars($s['phone']); ?></td>
                    <td><?php echo htmlspecialchars($s['shop_address']); ?></td>
                    <td><?php echo htmlspecialchars($s['business_type']); ?></td>
                    <td><?php echo htmlspecialchars($s['applied_at']); ?></td>
                    <td>
                        <form method="POST" action="seller_approvals.php" style="display:inline;">
                            <input type="hidden" name="seller_id" value="<?php echo $s['seller_id']; ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit">Approve</button>
                        </form>
                        <form method="POST" action="seller_approvals.php" style="display:inline;">
                            <input type="hidden" name="seller_id" value="<?php echo $s['seller_id']; ?>">
                            <input type="hidden" name="action" value="reject">
                            <button type="submit">Reject</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>