<?php
// Moves one order to a new status, enforcing the same rules the page forms use.
// Returns JSON so a page can update without a reload.
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/order_status.php';

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
$role = $_SESSION['role'];

$order_id = $_POST['order_id'] ?? '';
$new_status = $_POST['status'] ?? '';

if (!ctype_digit((string)$order_id) || $new_status === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}
$order_id = (int) $order_id;

// Load the order together with the ids that prove who is allowed to touch it
$stmt = mysqli_prepare(
    $conn,
    "SELECT o.order_id, o.status, o.customer_id, o.rider_id,
            sp.user_id AS seller_user_id, rp.user_id AS rider_user_id
     FROM orders o
     JOIN seller_profiles sp ON sp.seller_id = o.seller_id
     LEFT JOIN rider_profiles rp ON rp.rider_id = o.rider_id
     WHERE o.order_id = ?"
);
mysqli_stmt_bind_param($stmt, 'i', $order_id);
mysqli_stmt_execute($stmt);
$order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$order) {
    http_response_code(404);
    echo json_encode(['error' => 'Order not found']);
    exit;
}

// Being the right role is not enough — it has to be this user's order
$owns = false;
if ($role === 'seller') {
    $owns = ((int) $order['seller_user_id'] === (int) $user_id);
} elseif ($role === 'rider') {
    $owns = ($order['rider_user_id'] !== null && (int) $order['rider_user_id'] === (int) $user_id);
} elseif ($role === 'customer') {
    $owns = ((int) $order['customer_id'] === (int) $user_id);
}

if (!$owns) {
    http_response_code(403);
    echo json_encode(['error' => 'Not your order']);
    exit;
}

if (!can_transition($role, $order['status'], $new_status)) {
    http_response_code(409);
    echo json_encode([
        'error' => "A " . $role . " cannot move an order from '" . $order['status'] . "' to '" . $new_status . "'",
    ]);
    exit;
}

mysqli_begin_transaction($conn);
try {
    // Pinning the expected status makes a double submit a no-op rather than a
    // second transition
    $stmt = mysqli_prepare($conn, "UPDATE orders SET status = ? WHERE order_id = ? AND status = ?");
    mysqli_stmt_bind_param($stmt, 'sis', $new_status, $order_id, $order['status']);
    mysqli_stmt_execute($stmt);
    $changed = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($changed === 0) {
        throw new Exception('Status already moved');
    }

    if ($new_status === 'cancelled') {
        restore_order_stock($conn, $order_id);
    }

    if ($new_status === 'delivered') {
        require_once '../config.php';

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO earnings (rider_id, order_id, amount)
             SELECT rider_id, order_id, ROUND(delivery_fee * ?, 2)
             FROM orders WHERE order_id = ? AND rider_id IS NOT NULL"
        );
        $rate = RIDER_EARNING_RATE;
        mysqli_stmt_bind_param($stmt, 'di', $rate, $order_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE rider_profiles SET availability_status = 'available' WHERE rider_id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'i', $order['rider_id']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    // Everyone on the order except whoever made the change hears about it
    $message = "Order #" . $order_id . " is now " . str_replace('_', ' ', $new_status) . ".";
    $recipients = [(int) $order['customer_id'], (int) $order['seller_user_id']];
    if ($order['rider_user_id'] !== null) {
        $recipients[] = (int) $order['rider_user_id'];
    }
    foreach (array_unique($recipients) as $recipient) {
        if ($recipient !== (int) $user_id) {
            notify_user($conn, $recipient, $message);
        }
    }

    mysqli_commit($conn);
    echo json_encode(['ok' => true, 'order_id' => $order_id, 'status' => $new_status]);
} catch (Exception $e) {
    mysqli_rollback($conn);
    http_response_code(409);
    echo json_encode(['error' => 'That order changed while you were working — reload and retry']);
}
