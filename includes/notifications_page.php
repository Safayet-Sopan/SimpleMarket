<?php
// Shared notifications page. Each role's notifications.php sets $role_css and
// includes this, so the read/unread logic lives in exactly one place.
// Expects: an established session, $conn, and $role_css.

/** @var mysqli $conn */

if (!isset($role_css)) {
    $role_css = 'style.css';
}

$user_id = $_SESSION['user_id'];
$actionErr = "";
$successMsg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_all') {
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        mysqli_stmt_bind_param($stmt, 'i', $user_id);
        mysqli_stmt_execute($stmt);
        $marked = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        $successMsg = $marked . " notification(s) marked as read.";

    } elseif ($action === 'mark_read') {
        $notification_id = $_POST['notification_id'] ?? '';
        if (!ctype_digit((string)$notification_id)) {
            $actionErr = "Invalid request.";
        } else {
            $notification_id = (int) $notification_id;
            // user_id in the WHERE clause is what stops one user marking another's
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?"
            );
            mysqli_stmt_bind_param($stmt, 'ii', $notification_id, $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $successMsg = "Marked as read.";
        }

    } elseif ($action === 'clear_read') {
        $stmt = mysqli_prepare($conn, "DELETE FROM notifications WHERE user_id = ? AND is_read = 1");
        mysqli_stmt_bind_param($stmt, 'i', $user_id);
        mysqli_stmt_execute($stmt);
        $removed = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        $successMsg = $removed . " read notification(s) cleared.";
    }
}

// Unread first, newest first within each group
$notifications = [];
$stmt = mysqli_prepare(
    $conn,
    "SELECT notification_id, message, is_read, created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY is_read ASC, created_at DESC"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $notifications[] = $row;
}
mysqli_stmt_close($stmt);

$unread_count = 0;
foreach ($notifications as $n) {
    if (!$n['is_read']) {
        $unread_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Notifications — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/<?php echo htmlspecialchars($role_css); ?>">
</head>

<body>
    <h1>Notifications <span id="unread-badge"><?php echo $unread_count > 0 ? '(' . $unread_count . ')' : ''; ?></span></h1>

    <?php if ($successMsg): ?>
        <p class="success"><?php echo htmlspecialchars($successMsg); ?></p>
    <?php endif; ?>
    <?php if ($actionErr): ?>
        <p class="error"><?php echo htmlspecialchars($actionErr); ?></p>
    <?php endif; ?>

    <?php if (empty($notifications)): ?>
        <p>Nothing here yet.</p>
    <?php else: ?>
        <form method="POST" action="notifications.php">
            <button type="submit" name="action" value="mark_all">Mark All Read</button>
            <button type="submit" name="action" value="clear_read">Clear Read</button>
        </form>

        <table border="1" cellpadding="8">
            <tr>
                <th></th>
                <th>Message</th>
                <th>When</th>
                <th></th>
            </tr>
            <?php foreach ($notifications as $n): ?>
                <tr class="<?php echo $n['is_read'] ? 'notice' : 'alert'; ?>">
                    <td><?php echo $n['is_read'] ? 'read' : 'NEW'; ?></td>
                    <td><?php echo htmlspecialchars($n['message']); ?></td>
                    <td><?php echo htmlspecialchars($n['created_at']); ?></td>
                    <td>
                        <?php if (!$n['is_read']): ?>
                            <form method="POST" action="notifications.php">
                                <input type="hidden" name="notification_id" value="<?php echo $n['notification_id']; ?>">
                                <button type="submit" name="action" value="mark_read">Mark Read</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <a href="dashboard.php">Back to Dashboard</a>

    <script src="../assets/js/main.js"></script>
</body>

</html>
