<?php
// Shared order chat page. Each role includes this after setting $role_css.
// Expects: an established session, $conn, $role_css.
// Authorisation lives in includes/order_chat.php so the AJAX endpoints and this
// page agree on exactly who may see a thread.

require_once __DIR__ . '/order_chat.php';

/** @var mysqli $conn */

if (!isset($role_css)) {
    $role_css = 'style.css';
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$order_id = $_GET['order_id'] ?? '';

$accessErr = "";
$order = null;

if (ctype_digit((string)$order_id)) {
    $order_id = (int) $order_id;
    $order = chat_participants($conn, $order_id);

    if (!can_access_chat($order, $user_id)) {
        $accessErr = "That order's chat is not available to you.";
        $order = null;
    }
} else {
    $order_id = 0;
}

// Threads this user can open: every order they are party to that is still live.
// Delivered orders stay open for a while so a delivery can still be discussed.
if ($role === 'customer') {
    $where = "o.customer_id = ?";
} elseif ($role === 'seller') {
    $where = "sp.user_id = ?";
} else {
    $where = "rp.user_id = ?";
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT o.order_id, o.status, sp.shop_name, cu.full_name AS customer_name,
            (SELECT COUNT(*) FROM messages m
             WHERE m.order_id = o.order_id AND m.sender_id != ? AND m.is_read = 0) AS unread,
            (SELECT COUNT(*) FROM messages m WHERE m.order_id = o.order_id) AS total
     FROM orders o
     JOIN seller_profiles sp ON sp.seller_id = o.seller_id
     JOIN users cu ON cu.user_id = o.customer_id
     LEFT JOIN rider_profiles rp ON rp.rider_id = o.rider_id
     WHERE " . $where . " AND o.status != 'cancelled'
     ORDER BY o.created_at DESC"
);
mysqli_stmt_bind_param($stmt, 'ii', $user_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$threads = [];
while ($row = mysqli_fetch_assoc($result)) {
    $threads[] = $row;
}
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Order Chat — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/<?php echo htmlspecialchars($role_css); ?>">
</head>

<body>
    <h1>Order Chat</h1>

    <?php if ($accessErr): ?>
        <p class="error"><?php echo htmlspecialchars($accessErr); ?></p>
    <?php endif; ?>

    <p class="notice">Messages are tied to a single order. The customer, the shop and the
        assigned rider can all see this thread. It refreshes every few seconds.</p>

    <h2>Your Threads</h2>

    <?php if (empty($threads)): ?>
        <p>No orders to talk about yet.</p>
    <?php else: ?>
        <table border="1" cellpadding="8">
            <tr>
                <th>Order</th>
                <th>Shop</th>
                <th>Customer</th>
                <th>Status</th>
                <th>Messages</th>
                <th></th>
            </tr>
            <?php foreach ($threads as $t): ?>
                <tr class="<?php echo $t['unread'] > 0 ? 'alert' : 'notice'; ?>">
                    <td>#<?php echo $t['order_id']; ?></td>
                    <td><?php echo htmlspecialchars($t['shop_name']); ?></td>
                    <td><?php echo htmlspecialchars($t['customer_name']); ?></td>
                    <td><?php echo str_replace('_', ' ', $t['status']); ?></td>
                    <td>
                        <?php echo $t['total']; ?>
                        <?php if ($t['unread'] > 0): ?>
                            — <strong><?php echo $t['unread']; ?> new</strong>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$t['order_id'] === $order_id): ?>
                            <strong>open</strong>
                        <?php else: ?>
                            <a href="?order_id=<?php echo $t['order_id']; ?>">Open</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <?php if ($order): ?>
        <h2>Order #<?php echo $order['order_id']; ?> — <?php echo htmlspecialchars($order['shop_name']); ?></h2>
        <p>Status: <?php echo str_replace('_', ' ', $order['status']); ?></p>

        <div id="chat-messages" data-order-id="<?php echo $order['order_id']; ?>"
             style="height:300px; overflow-y:auto; border:1px solid #000; padding:8px;">
        </div>

        <p id="chat-status"></p>

        <form id="chat-form">
            <input type="text" id="chat-input" size="60" placeholder="Type a message" autocomplete="off">
            <button type="submit">Send</button>
        </form>

        <script src="../assets/js/chat_poll.js"></script>
    <?php elseif (!$accessErr): ?>
        <p>Pick a thread above to start talking.</p>
    <?php endif; ?>

    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>
