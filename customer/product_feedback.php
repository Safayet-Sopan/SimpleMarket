<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('customer');

/** @var mysqli $conn */

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

$customer_id = $_SESSION['user_id'];

$ratingErr = $commentErr = $actionErr = "";
$successMsg = "";
$rating = "";
$comment = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $order_id = $_POST['order_id'] ?? '';
    $product_id = $_POST['product_id'] ?? '';

    if (!ctype_digit((string)$order_id) || !ctype_digit((string)$product_id)) {
        $actionErr = "Invalid request.";
    } else {
        $order_id = (int) $order_id;
        $product_id = (int) $product_id;

        // Rating
        $rating = $_POST['rating'] ?? '';
        if ($rating === '') {
            $ratingErr = "Pick a rating";
        } elseif (!ctype_digit((string)$rating) || (int)$rating < 1 || (int)$rating > 5) {
            $ratingErr = "Rating must be between 1 and 5";
        } else {
            $rating = (int) $rating;
        }

        // Comment is optional, but capped so it stays readable
        $comment = cleanInput($_POST['comment'] ?? '');
        if (strlen($comment) > 500) {
            $commentErr = "Keep the comment under 500 characters";
        }

        if (!$ratingErr && !$commentErr) {
            // Only a delivered order this customer owns, containing this product,
            // and not already reviewed, may be reviewed.
            $stmt = mysqli_prepare(
                $conn,
                "SELECT o.order_id
                 FROM orders o
                 JOIN order_items oi ON oi.order_id = o.order_id
                 WHERE o.order_id = ? AND o.customer_id = ? AND o.status = 'delivered'
                   AND oi.product_id = ?"
            );
            mysqli_stmt_bind_param($stmt, 'iii', $order_id, $customer_id, $product_id);
            mysqli_stmt_execute($stmt);
            $eligible = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if (!$eligible) {
                $actionErr = "You can only review a product from an order that reached you.";
            } else {
                $stmt = mysqli_prepare(
                    $conn,
                    "SELECT review_id FROM reviews WHERE order_id = ? AND product_id = ? AND customer_id = ?"
                );
                mysqli_stmt_bind_param($stmt, 'iii', $order_id, $product_id, $customer_id);
                mysqli_stmt_execute($stmt);
                $already = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);

                if ($already) {
                    $actionErr = "You have already reviewed that product for this order.";
                } else {
                    mysqli_begin_transaction($conn);
                    try {
                        $stmt = mysqli_prepare(
                            $conn,
                            "INSERT INTO reviews (product_id, customer_id, order_id, rating, comment)
                             VALUES (?, ?, ?, ?, ?)"
                        );
                        mysqli_stmt_bind_param($stmt, 'iiiis', $product_id, $customer_id, $order_id, $rating, $comment);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);

                        // Let the shop know a review landed
                        $notif_message = "New " . $rating . "-star product review on order #" . $order_id . ".";
                        $stmt = mysqli_prepare(
                            $conn,
                            "INSERT INTO notifications (user_id, message)
                             SELECT sp.user_id, ? FROM seller_profiles sp
                             JOIN products p ON p.seller_id = sp.seller_id
                             WHERE p.product_id = ?"
                        );
                        mysqli_stmt_bind_param($stmt, 'si', $notif_message, $product_id);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);

                        mysqli_commit($conn);
                        $successMsg = "Thanks — your review is live.";
                        $rating = "";
                        $comment = "";
                    } catch (Exception $e) {
                        mysqli_rollback($conn);
                        $actionErr = "Could not save your review. Please try again.";
                    }
                }
            }
        }
    }
}

// Everything this customer received but has not reviewed yet
$pending_reviews = [];
$stmt = mysqli_prepare(
    $conn,
    "SELECT o.order_id, o.created_at, p.product_id, p.product_name, sp.shop_name,
            oi.quantity, oi.unit_price
     FROM orders o
     JOIN order_items oi ON oi.order_id = o.order_id
     JOIN products p ON p.product_id = oi.product_id
     JOIN seller_profiles sp ON sp.seller_id = o.seller_id
     WHERE o.customer_id = ? AND o.status = 'delivered'
       AND NOT EXISTS (
           SELECT 1 FROM reviews r
           WHERE r.order_id = o.order_id AND r.product_id = p.product_id
             AND r.customer_id = o.customer_id
       )
     ORDER BY o.created_at DESC"
);
mysqli_stmt_bind_param($stmt, 'i', $customer_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $pending_reviews[] = $row;
}
mysqli_stmt_close($stmt);

// Reviews this customer has already written
$my_reviews = [];
$stmt = mysqli_prepare(
    $conn,
    "SELECT r.rating, r.comment, r.created_at, r.order_id, p.product_name
     FROM reviews r
     JOIN products p ON p.product_id = r.product_id
     WHERE r.customer_id = ?
     ORDER BY r.created_at DESC"
);
mysqli_stmt_bind_param($stmt, 'i', $customer_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $my_reviews[] = $row;
}
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Product Feedback — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/customer.css">
</head>

<body>
    <h1>Product Feedback</h1>

    <?php if ($successMsg): ?>
        <p class="success"><?php echo htmlspecialchars($successMsg); ?></p>
    <?php endif; ?>
    <?php if ($actionErr): ?>
        <p class="error"><?php echo htmlspecialchars($actionErr); ?></p>
    <?php endif; ?>

    <h2>Waiting for Your Review</h2>

    <?php if (empty($pending_reviews)): ?>
        <p>Nothing to review. Reviews unlock once an order is delivered.</p>
    <?php else: ?>
        <?php foreach ($pending_reviews as $pr): ?>
            <table border="1" cellpadding="8">
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($pr['product_name']); ?></strong><br>
                        from <?php echo htmlspecialchars($pr['shop_name']); ?>
                        — order #<?php echo $pr['order_id']; ?>
                        (<?php echo $pr['quantity']; ?> x
                        ৳<?php echo number_format($pr['unit_price'], 2); ?>)
                    </td>
                    <td>
                        <form method="POST" action="product_feedback.php">
                            <input type="hidden" name="order_id" value="<?php echo $pr['order_id']; ?>">
                            <input type="hidden" name="product_id" value="<?php echo $pr['product_id']; ?>">

                            <label>Rating</label>
                            <select name="rating">
                                <option value="">--</option>
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?> star<?php echo $i > 1 ? 's' : ''; ?></option>
                                <?php endfor; ?>
                            </select>

                            <label>Comment (optional)</label>
                            <input type="text" name="comment" size="40">

                            <button type="submit">Submit Review</button>
                        </form>
                        <span class="error"><?php echo $ratingErr; ?></span>
                        <span class="error"><?php echo $commentErr; ?></span>
                    </td>
                </tr>
            </table>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2>Your Past Reviews</h2>

    <?php if (empty($my_reviews)): ?>
        <p>You have not reviewed anything yet.</p>
    <?php else: ?>
        <table border="1" cellpadding="8">
            <tr>
                <th>Product</th>
                <th>Order</th>
                <th>Rating</th>
                <th>Comment</th>
                <th>Written</th>
            </tr>
            <?php foreach ($my_reviews as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['product_name']); ?></td>
                    <td>#<?php echo $r['order_id']; ?></td>
                    <td><?php echo $r['rating']; ?> / 5</td>
                    <td><?php echo htmlspecialchars($r['comment']); ?></td>
                    <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <a href="seller_rating.php">Rate a Shop</a>
    <a href="order_tracking.php">My Orders</a>
    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>
