<?php
// Returns the messages on one order as JSON, newest last.
// Polled by assets/js/chat_poll.js.
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/order_chat.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

/** @var mysqli $conn */
$user_id = $_SESSION['user_id'];
$order_id = $_GET['order_id'] ?? '';

if (!ctype_digit((string)$order_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid order']);
    exit;
}
$order_id = (int) $order_id;

$order = chat_participants($conn, $order_id);
if (!can_access_chat($order, $user_id)) {
    http_response_code(403);
    echo json_encode(['error' => 'Not your order']);
    exit;
}

// Only fetch what the client has not seen yet
$after_id = $_GET['after_id'] ?? '0';
$after_id = ctype_digit((string)$after_id) ? (int) $after_id : 0;

$stmt = mysqli_prepare(
    $conn,
    "SELECT m.message_id, m.message_text, m.sent_at, m.sender_id,
            u.full_name AS sender_name, u.role AS sender_role
     FROM messages m
     JOIN users u ON u.user_id = m.sender_id
     WHERE m.order_id = ? AND m.message_id > ?
     ORDER BY m.message_id ASC"
);
mysqli_stmt_bind_param($stmt, 'ii', $order_id, $after_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$messages = [];
while ($row = mysqli_fetch_assoc($result)) {
    $messages[] = [
        'message_id' => (int) $row['message_id'],
        'text'       => $row['message_text'],
        'sent_at'    => $row['sent_at'],
        'sender'     => $row['sender_name'],
        'role'       => $row['sender_role'],
        'is_mine'    => ((int) $row['sender_id'] === (int) $user_id),
    ];
}
mysqli_stmt_close($stmt);

// Mark everyone else's messages in this thread as read
$stmt = mysqli_prepare(
    $conn,
    "UPDATE messages SET is_read = 1 WHERE order_id = ? AND sender_id != ? AND is_read = 0"
);
mysqli_stmt_bind_param($stmt, 'ii', $order_id, $user_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

echo json_encode(['messages' => $messages, 'order_status' => $order['status']]);
