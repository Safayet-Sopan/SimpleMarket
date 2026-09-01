<?php
// Posts one message onto an order's chat thread.
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/order_chat.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

/** @var mysqli $conn */
$user_id = $_SESSION['user_id'];
$order_id = $_POST['order_id'] ?? '';
$message_text = trim($_POST['message_text'] ?? '');

if (!ctype_digit((string)$order_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid order']);
    exit;
}
$order_id = (int) $order_id;

if ($message_text === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Message is empty']);
    exit;
}
if (mb_strlen($message_text) > 1000) {
    http_response_code(400);
    echo json_encode(['error' => 'Message is too long']);
    exit;
}

$order = chat_participants($conn, $order_id);
if (!can_access_chat($order, $user_id)) {
    http_response_code(403);
    echo json_encode(['error' => 'Not your order']);
    exit;
}

// Stored raw and escaped on output, so the text survives intact either way
$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO messages (order_id, sender_id, message_text) VALUES (?, ?, ?)"
);
mysqli_stmt_bind_param($stmt, 'iis', $order_id, $user_id, $message_text);
mysqli_stmt_execute($stmt);
$message_id = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

echo json_encode(['ok' => true, 'message_id' => $message_id]);
