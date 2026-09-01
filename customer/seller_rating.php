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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $order_id = $_POST['order_id'] ?? '';

    if (!ctype_digit((string)$order_id)) {
        $actionErr = "Invalid request.";
    } else {
        $order_id = (int) $order_id;

        $rating = $_POST['rating'] ?? '';
        if ($rating === '') {
            $ratingErr = "Pick a rating";
        } elseif (!ctype_digit((string)$rating) || (int)$rating < 1 || (int)$rating > 5) {
            $ratingErr = "Rating must be between 1 and 5";
        } else {
            $rating = (int) $rating;
        }

        $comment = cleanInput($_POST['comment'] ?? '');
        if (strlen($comment) > 500) {
            $commentErr = "Keep the comment under 500 characters";
        }

        if (!$ratingErr && !$commentErr) {
            // Rating a shop requires a delivered order from that shop
            $stmt = mysqli_prepare(
                $conn,
                "SELECT seller_id FROM orders
                 WHERE order_id = ? AND customer_id = ? AND status = 'delivered'"
            );
            mysqli_stmt_bind_param($stmt, 'ii', $order_id, $customer_id);
            mysqli_stmt_execute($stmt);
            $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if (!$order) {
                $actionErr = "You can only rate a shop after an order from it is delivered.";
            } else {
                $stmt = mysqli_prepare(
                    $conn,
                    "SELECT rating_id FROM seller_ratings WHERE order_id = ? AND customer_id = ?"
                );
                mysqli_stmt_bind_param($stmt, 'ii', $order_id, $customer_id);
                mysqli_stmt_execute($stmt);
                $already = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);

                if ($already) {
                    $actionErr = "You have already rated the shop for that order.";
                } else {
                    mysqli_begin_transaction($conn);
                    try {
                        $stmt = mysqli_prepare(
                            $conn,
                            "INSERT INTO seller_ratings (seller_id, customer_id, order_id, rating, comment)
                             VALUES (?, ?, ?, ?, ?)"
                        );
                        mysqli_stmt_bind_param($stmt, 'iiiis', $order['seller_id'], $customer_id, $order_id, $rating, $comment);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);

                        $notif_message = "Your shop received a " . $rating . "-star rating on order #" . $order_id . ".";
                        $stmt = mysqli_prepare(
                            $conn,
                            "INSERT INTO notifications (user_id, message)
                             SELECT user_id, ? FROM seller_profiles WHERE seller_id = ?"
                        );
                        mysqli_stmt_bind_param($stmt, 'si', $notif_message, $order['seller_id']);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);

                        mysqli_commit($conn);
                        $successMsg = "Thanks — your rating has been recorded.";
                    } catch (Exception $e) {
                        mysqli_rollback($conn);
                        $actionErr = "Could not save your rating. Please try again.";
                    }
                }
            }
        }
    }
}

// Delivered orders this customer has not rated the shop for
$pending = [];
$stmt = mysqli_prepare(
    $conn,
    "SELECT o.order_id, o.created_at, o.total_amount, sp.seller_id, sp.shop_name
     FROM orders o
     JOIN seller_profiles sp ON sp.seller_id = o.seller_id
     WHERE o.customer_id = ? AND o.status = 'delivered'
       AND NOT EXISTS (
           SELECT 1 FROM seller_ratings sr
           WHERE sr.order_id = o.order_id AND sr.customer_id = o.customer_id
       )
     ORDER BY o.created_at DESC"
);
mysqli_stmt_bind_param($stmt, 'i', $customer_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $pending[] = $row;
}
mysqli_stmt_close($stmt);

// Ratings this customer has given, with the shop's running average alongside
$my_ratings = [];
$stmt = mysqli_prepare(
    $conn,
    "SELECT sr.rating, sr.comment, sr.created_at, sr.order_id, sp.shop_name,
            (SELECT ROUND(AVG(rating), 2) FROM seller_ratings WHERE seller_id = sr.seller_id) AS shop_average,
            (SELECT COUNT(*) FROM seller_ratings WHERE seller_id = sr.seller_id) AS shop_rating_count
     FROM seller_ratings sr
     JOIN seller_profiles sp ON sp.seller_id = sr.seller_id
     WHERE sr.customer_id = ?
     ORDER BY sr.created_at DESC"
);
mysqli_stmt_bind_param($stmt, 'i', $customer_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $my_ratings[] = $row;
}
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Rate a Shop — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/customer.css">
</head>

<body>
    <h1>Seller Rating</h1>

    <?php if ($successMsg): ?>
        <p class="success"><?php echo htmlspecialchars($successMsg); ?></p>
    <?php endif; ?>
    <?php if ($actionErr): ?>
        <p class="error"><?php echo htmlspecialchars($actionErr); ?></p>
    <?php endif; ?>

    <p class="notice">Rating the shop is separate from reviewing the product. This one is
        about the seller — packaging, accuracy, how they handled the order.</p>

    <h2>Shops Waiting on Your Rating</h2>

    <?php if (empty($pending)): ?>
        <p>Nothing to rate right now. Shop ratings unlock once an order is delivered.</p>
    <?php else: ?>
        <?php foreach ($pending as $p): ?>
            <table border="1" cellpadding="8">
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($p['shop_name']); ?></strong><br>
                        order #<?php echo $p['order_id']; ?>
                        — ৳<?php echo number_format($p['total_amount'], 2); ?><br>
                        <small><?php echo htmlspecialchars($p['created_at']); ?></small>
                    </td>
                    <td>
                        <form method="POST" action="seller_rating.php">
                            <input type="hidden" name="order_id" value="<?php echo $p['order_id']; ?>">

                            <label>Rating</label>
                            <select name="rating">
                                <option value="">--</option>
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?> star<?php echo $i > 1 ? 's' : ''; ?></option>
                                <?php endfor; ?>
                            </select>

                            <label>Comment (optional)</label>
                            <input type="text" name="comment" size="40">

                            <button type="submit">Rate Shop</button>
                        </form>
                        <span class="error"><?php echo $ratingErr; ?></span>
                        <span class="error"><?php echo $commentErr; ?></span>
                    </td>
                </tr>
            </table>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2>Ratings You Have Given</h2>

    <?php if (empty($my_ratings)): ?>
        <p>You have not rated any shop yet.</p>
    <?php else: ?>
        <table border="1" cellpadding="8">
            <tr>
                <th>Shop</th>
                <th>Order</th>
                <th>You Gave</th>
                <th>Shop Average</th>
                <th>Comment</th>
                <th>Rated</th>
            </tr>
            <?php foreach ($my_ratings as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['shop_name']); ?></td>
                    <td>#<?php echo $r['order_id']; ?></td>
                    <td><?php echo $r['rating']; ?> / 5</td>
                    <td>
                        <?php echo htmlspecialchars($r['shop_average']); ?> / 5
                        <small>(<?php echo $r['shop_rating_count']; ?> rating(s))</small>
                    </td>
                    <td><?php echo htmlspecialchars($r['comment']); ?></td>
                    <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <a href="product_feedback.php">Product Feedback</a>
    <a href="order_tracking.php">My Orders</a>
    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>
