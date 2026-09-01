<?php
// Authorisation helper shared by the chat pages and the AJAX chat endpoints.
// Chat is strictly order-scoped: only the customer, the shop owner and the
// assigned rider on that order may read or post.

function chat_participants($conn, $order_id)
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT o.order_id, o.status, o.customer_id,
                sp.user_id AS seller_user_id, sp.shop_name,
                rp.user_id AS rider_user_id
         FROM orders o
         JOIN seller_profiles sp ON sp.seller_id = o.seller_id
         LEFT JOIN rider_profiles rp ON rp.rider_id = o.rider_id
         WHERE o.order_id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'i', $order_id);
    mysqli_stmt_execute($stmt);
    $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $order;
}

function can_access_chat($order, $user_id)
{
    if (!$order) {
        return false;
    }

    return (int) $order['customer_id'] === (int) $user_id
        || (int) $order['seller_user_id'] === (int) $user_id
        || ($order['rider_user_id'] !== null && (int) $order['rider_user_id'] === (int) $user_id);
}
