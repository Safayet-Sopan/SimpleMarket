<?php
// Notifications. Every role reads the same table; rows are always scoped to
// one user_id, which is what keeps one role from reading another's.

function notification_unread_count($conn, $user_id)
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0"
    );
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return (int) ($row['cnt'] ?? 0);
}

function notification_list($conn, $user_id)
{
    $rows = [];
    $stmt = mysqli_prepare(
        $conn,
        "SELECT notification_id, message, is_read, created_at
         FROM notifications WHERE user_id = ? ORDER BY is_read ASC, created_at DESC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

// user_id in the WHERE clause is what stops one user marking another's read.
function notification_mark_read($conn, $notification_id, $user_id)
{
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $notification_id, $user_id);
    mysqli_stmt_execute($stmt);
    $n = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $n;
}

function notification_mark_all_read($conn, $user_id)
{
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0"
    );
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $n = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $n;
}

// Clearing only removes what the user has already seen, so an unread notice
// cannot be thrown away before it is read.
function notification_clear_read($conn, $user_id)
{
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM notifications WHERE user_id = ? AND is_read = 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $n = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $n;
}

function notify_user($conn, $user_id, $message)
{
    $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, 'is', $user_id, $message);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// Resolves a seller_id to the account that owns the shop, so callers holding a
// seller_id do not have to look the user up first.
function notify_seller($conn, $seller_id, $message)
{
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO notifications (user_id, message)
         SELECT user_id, ? FROM seller_profiles WHERE seller_id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'si', $message, $seller_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// The newest unread notice, so the poller can show what actually arrived.
function notification_latest_unread($conn, $user_id)
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT message, created_at FROM notifications
         WHERE user_id = ? AND is_read = 0
         ORDER BY created_at DESC LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row;
}
