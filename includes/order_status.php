<?php
// Single source of truth for who may move an order to which status.
// Used by seller/orders.php, rider/deliveries.php and ajax/update_order_status.php
// so the forms and the AJAX endpoint can never disagree.

function order_transitions($role)
{
    if ($role === 'seller') {
        return [
            'pending'   => ['confirmed', 'cancelled'],
            'confirmed' => ['preparing', 'cancelled'],
            'preparing' => ['cancelled'],
        ];
    }

    if ($role === 'rider') {
        return [
            'preparing'        => ['out_for_delivery'],
            'out_for_delivery' => ['delivered'],
        ];
    }

    if ($role === 'customer') {
        // A customer may back out only before the shop starts work
        return [
            'pending' => ['cancelled'],
        ];
    }

    return [];
}

function can_transition($role, $from, $to)
{
    $map = order_transitions($role);
    return isset($map[$from]) && in_array($to, $map[$from], true);
}

// Restores stock for a cancelled order. Call inside a transaction.
function restore_order_stock($conn, $order_id)
{
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE products p
         JOIN order_items oi ON oi.product_id = p.product_id
         SET p.stock_quantity = p.stock_quantity + oi.quantity
         WHERE oi.order_id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'i', $order_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function notify_user($conn, $user_id, $message)
{
    $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, 'is', $user_id, $message);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
