<?php
// Returns the caller's unread notification count as JSON.
// Polled by assets/js/main.js — there are no sockets in this project.
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

/** @var mysqli $conn */
$user_id = $_SESSION['user_id'];

$stmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS unread FROM notifications WHERE user_id = ? AND is_read = 0"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

// Newest unread message, so the page can show what actually arrived
$latest = null;
$stmt = mysqli_prepare(
    $conn,
    "SELECT message, created_at FROM notifications
     WHERE user_id = ? AND is_read = 0
     ORDER BY created_at DESC LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$latest = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

echo json_encode([
    'unread' => (int) $row['unread'],
    'latest' => $latest ? $latest['message'] : null,
]);
