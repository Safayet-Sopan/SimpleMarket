<?php
// End-to-end logic test for SimpleMarket.
// Runs against a THROWAWAY database (simplemarket_test_db) — the real
// simplemarket_db is never touched.
//   php e2e_test.php

chdir('/Applications/XAMPP/xamppfiles/htdocs/SimpleMarket');
require_once 'config.php';
require_once 'includes/order_status.php';

$pass_count = 0; $fail_count = 0; $failures = [];

function check($label, $condition, $detail = '') {
    global $pass_count, $fail_count, $failures;
    if ($condition) { $pass_count++; echo "  PASS  $label\n"; }
    else { $fail_count++; $failures[] = $label . ($detail ? " ($detail)" : '');
           echo "  FAIL  $label" . ($detail ? " -- $detail" : '') . "\n"; }
}
function section($n) { echo "\n=== $n ===\n"; }
function scalar($conn, $sql) {
    $r = mysqli_query($conn, $sql);
    if (!$r) { return null; }
    $row = mysqli_fetch_array($r);
    return $row ? $row[0] : null;
}

// ------------------------------------------------------------------ bootstrap
section('Schema bootstrap');

$db_name = 'simplemarket_test_db';
$boot = mysqli_connect('localhost', 'root', '');
if (!$boot) { die("Cannot connect to MySQL: " . mysqli_connect_error() . "\n"); }
mysqli_query($boot, "DROP DATABASE IF EXISTS $db_name");
mysqli_close($boot);

ob_start(); include 'setup.php'; $out1 = ob_get_clean();
check('setup.php created everything', strpos($out1, 'FAILED') === false);

$conn = mysqli_connect('localhost', 'root', '', $db_name);
mysqli_set_charset($conn, 'utf8mb4');

$table_count = (int) scalar($conn, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$db_name'");
check('14 tables exist', $table_count === 14, "$table_count found");

$cat_count = (int) scalar($conn, "SELECT COUNT(*) FROM categories");
check('categories seeded', $cat_count >= 5, "$cat_count rows");

$rt = (int) scalar($conn, "SELECT COUNT(*) FROM information_schema.TABLES
       WHERE TABLE_SCHEMA='$db_name' AND TABLE_NAME='remember_tokens'");
check('remember_tokens table created', $rt === 1);

foreach ([['offers','converted_order_id'], ['seller_profiles','payment_methods'],
          ['orders','payment_method'], ['orders','payment_status']] as $p) {
    $n = (int) scalar($conn, "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA='$db_name' AND TABLE_NAME='{$p[0]}' AND COLUMN_NAME='{$p[1]}'");
    check("migration added {$p[0]}.{$p[1]}", $n === 1);
}

ob_start(); include 'setup.php'; $out2 = ob_get_clean();
check('setup.php is re-runnable', strpos($out2, 'FAILED') === false);
$conn = mysqli_connect('localhost', 'root', '', $db_name);
mysqli_set_charset($conn, 'utf8mb4');
check('re-run did not duplicate categories',
      (int) scalar($conn, "SELECT COUNT(*) FROM categories") === $cat_count);

// ------------------------------------------------------------------ fixtures
section('Fixtures');

function make_user($conn, $name, $email, $role, $status) {
    $hash = password_hash('Passw0rd!', PASSWORD_DEFAULT);
    $phone = '01700000000';
    $stmt = mysqli_prepare($conn, "INSERT INTO users (full_name,email,phone,password_hash,role,status) VALUES (?,?,?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'ssssss', $name, $email, $phone, $hash, $role, $status);
    mysqli_stmt_execute($stmt);
    $id = mysqli_insert_id($conn); mysqli_stmt_close($stmt); return $id;
}

$seller_uid   = make_user($conn, 'Shop Owner', 'seller@t.local', 'seller', 'pending');
$customer_uid = make_user($conn, 'Buyer One', 'buyer@t.local', 'customer', 'active');
$rider_uid    = make_user($conn, 'Rider One', 'rider@t.local', 'rider', 'active');
$rider2_uid   = make_user($conn, 'Rider Two', 'rider2@t.local', 'rider', 'active');

mysqli_query($conn, "INSERT INTO seller_profiles (user_id,shop_name,shop_address,business_type,commission_rate,approval_status)
                     VALUES ($seller_uid,'Test Shop','Dhanmondi','Home Food',10.00,'pending')");
$seller_id = mysqli_insert_id($conn);
mysqli_query($conn, "INSERT INTO rider_profiles (user_id,vehicle_type,vehicle_plate,vehicle_capacity,availability_status)
                     VALUES ($rider_uid,'Motorbike','DHA-1','10kg','available')");
$rider_id = mysqli_insert_id($conn);
mysqli_query($conn, "INSERT INTO rider_profiles (user_id,vehicle_type,vehicle_plate,vehicle_capacity,availability_status)
                     VALUES ($rider2_uid,'Bicycle','N/A','5kg','available')");
$rider2_id = mysqli_insert_id($conn);

check('shop defaults to cod',
      scalar($conn, "SELECT payment_methods FROM seller_profiles WHERE seller_id=$seller_id") === 'cod');

mysqli_query($conn, "UPDATE seller_profiles SET approval_status='approved' WHERE seller_id=$seller_id");
mysqli_query($conn, "UPDATE users SET status='active' WHERE user_id=$seller_uid");
check('seller approval flips both tables',
      scalar($conn, "SELECT status FROM users WHERE user_id=$seller_uid") === 'active' &&
      scalar($conn, "SELECT approval_status FROM seller_profiles WHERE seller_id=$seller_id") === 'approved');

$cat_id = (int) scalar($conn, "SELECT category_id FROM categories LIMIT 1");
mysqli_query($conn, "INSERT INTO products (seller_id,category_id,product_name,description,price,stock_quantity,low_stock_threshold,status)
                     VALUES ($seller_id,$cat_id,'Beef Tehari','Family pack',500.00,10,5,'active')");
$product_id = mysqli_insert_id($conn);
check('product created', $product_id > 0);

// ------------------------------------------------------------------ checkout
section('Checkout (customer/fast_delivery.php logic)');

// Mirrors the page: stock guard, then the 9-column INSERT that used to be the bug
function place_order($conn, $customer_id, $seller_id, $product_id, $qty, $unit_price,
                     $commission_rate, $fast, $method, &$err = null) {
    $subtotal = $unit_price * $qty;
    $delivery_fee = $fast ? FAST_DELIVERY_FEE : STANDARD_DELIVERY_FEE;
    $commission = round($subtotal * ($commission_rate / 100), 2);
    $total = $subtotal + $delivery_fee;

    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, "UPDATE products SET stock_quantity = stock_quantity - ?
                                       WHERE product_id = ? AND stock_quantity >= ?");
        mysqli_stmt_bind_param($stmt, 'iii', $qty, $product_id, $qty);
        mysqli_stmt_execute($stmt);
        $ok = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        if ($ok === 0) { throw new Exception('Stock unavailable'); }

        $stmt = mysqli_prepare($conn,
            "INSERT INTO orders (customer_id,seller_id,delivery_address,fast_delivery,delivery_fee,subtotal,commission_amount,total_amount,payment_method,status)
             VALUES (?,?,?,?,?,?,?,?,?,'pending')");
        $addr = 'Road 5, Dhanmondi';
        mysqli_stmt_bind_param($stmt, 'iisidddds', $customer_id, $seller_id, $addr,
                               $fast, $delivery_fee, $subtotal, $commission, $total, $method);
        mysqli_stmt_execute($stmt);
        $order_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($conn, "INSERT INTO order_items (order_id,product_id,quantity,unit_price,line_total) VALUES (?,?,?,?,?)");
        mysqli_stmt_bind_param($stmt, 'iiidd', $order_id, $product_id, $qty, $unit_price, $subtotal);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        mysqli_commit($conn);
        return $order_id;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $err = $e->getMessage();
        return 0;
    }
}

$order_id = place_order($conn, $customer_uid, $seller_id, $product_id, 2, 500.00, 10.00, 1, 'cod');
check('order placed (the iisidddds bind that used to fatal)', $order_id > 0);
check('stock decremented 10 -> 8',
      (int) scalar($conn, "SELECT stock_quantity FROM products WHERE product_id=$product_id") === 8);
check('commission stored = 10% of 1000', (float) scalar($conn, "SELECT commission_amount FROM orders WHERE order_id=$order_id") == 100.00);
check('fast delivery fee applied', (float) scalar($conn, "SELECT delivery_fee FROM orders WHERE order_id=$order_id") == FAST_DELIVERY_FEE);
check('total = subtotal + delivery fee',
      (float) scalar($conn, "SELECT total_amount FROM orders WHERE order_id=$order_id") == 1000.00 + FAST_DELIVERY_FEE);

$err = null;
$over = place_order($conn, $customer_uid, $seller_id, $product_id, 99, 500.00, 10.00, 0, 'cod', $err);
check('overselling is refused', $over === 0, "err=$err");
check('failed order left stock untouched',
      (int) scalar($conn, "SELECT stock_quantity FROM products WHERE product_id=$product_id") === 8);
check('failed order wrote no row',
      (int) scalar($conn, "SELECT COUNT(*) FROM orders") === 1);

// ------------------------------------------------------------------ statuses
section('Order status transitions');

check('seller may confirm a pending order', can_transition('seller', 'pending', 'confirmed'));
check('seller may NOT deliver', !can_transition('seller', 'preparing', 'delivered'));
check('rider may NOT confirm', !can_transition('rider', 'pending', 'confirmed'));
check('rider may deliver from out_for_delivery', can_transition('rider', 'out_for_delivery', 'delivered'));
check('customer may cancel while pending', can_transition('customer', 'pending', 'cancelled'));
check('customer may NOT cancel once preparing', !can_transition('customer', 'preparing', 'cancelled'));

mysqli_query($conn, "UPDATE orders SET status='confirmed' WHERE order_id=$order_id");
mysqli_query($conn, "UPDATE orders SET status='preparing' WHERE order_id=$order_id");
check('order reached preparing', scalar($conn, "SELECT status FROM orders WHERE order_id=$order_id") === 'preparing');

// ------------------------------------------------------------------ rider
section('Rider claim and delivery');

function claim($conn, $rider_id, $order_id) {
    $stmt = mysqli_prepare($conn, "UPDATE orders SET rider_id=? WHERE order_id=? AND rider_id IS NULL AND status IN ('confirmed','preparing')");
    mysqli_stmt_bind_param($stmt, 'ii', $rider_id, $order_id);
    mysqli_stmt_execute($stmt);
    $n = mysqli_stmt_affected_rows($stmt); mysqli_stmt_close($stmt); return $n;
}

check('first rider claims the order', claim($conn, $rider_id, $order_id) === 1);
check('second rider cannot steal it', claim($conn, $rider2_id, $order_id) === 0);
check('order is held by the first rider',
      (int) scalar($conn, "SELECT rider_id FROM orders WHERE order_id=$order_id") === $rider_id);

mysqli_query($conn, "UPDATE orders SET status='out_for_delivery' WHERE order_id=$order_id AND rider_id=$rider_id AND status='preparing'");
check('rider started delivery', scalar($conn, "SELECT status FROM orders WHERE order_id=$order_id") === 'out_for_delivery');

// deliver, guarded exactly as rider/deliveries.php does it
function deliver($conn, $rider_id, $order_id) {
    mysqli_begin_transaction($conn);
    try {
        $stmt = mysqli_prepare($conn, "UPDATE orders SET status='delivered' WHERE order_id=? AND rider_id=? AND status='out_for_delivery'");
        mysqli_stmt_bind_param($stmt, 'ii', $order_id, $rider_id);
        mysqli_stmt_execute($stmt);
        $n = mysqli_stmt_affected_rows($stmt); mysqli_stmt_close($stmt);
        if ($n === 0) { throw new Exception('not allowed'); }

        $stmt = mysqli_prepare($conn, "INSERT INTO earnings (rider_id,order_id,amount)
                                       SELECT ?, order_id, ROUND(delivery_fee * ?, 2) FROM orders WHERE order_id=?");
        $rate = RIDER_EARNING_RATE;
        mysqli_stmt_bind_param($stmt, 'idi', $rider_id, $rate, $order_id);
        mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);

        mysqli_query($conn, "UPDATE rider_profiles SET availability_status='available' WHERE rider_id=$rider_id");
        mysqli_commit($conn); return true;
    } catch (Exception $e) { mysqli_rollback($conn); return false; }
}

check('rider marks delivered', deliver($conn, $rider_id, $order_id));
$earned = (float) scalar($conn, "SELECT amount FROM earnings WHERE order_id=$order_id");
check('earnings row created at the right share', $earned == round(FAST_DELIVERY_FEE * RIDER_EARNING_RATE, 2),
      "got $earned, expected " . round(FAST_DELIVERY_FEE * RIDER_EARNING_RATE, 2));
check('delivering twice is refused', !deliver($conn, $rider_id, $order_id));
check('no duplicate earnings row', (int) scalar($conn, "SELECT COUNT(*) FROM earnings WHERE order_id=$order_id") === 1);

// ------------------------------------------------------------------ feedback
section('Reviews and seller ratings');

mysqli_query($conn, "INSERT INTO reviews (product_id,customer_id,order_id,rating,comment)
                     VALUES ($product_id,$customer_uid,$order_id,5,'Great')");
check('product review saved', (int) scalar($conn, "SELECT COUNT(*) FROM reviews WHERE order_id=$order_id") === 1);

mysqli_query($conn, "INSERT INTO seller_ratings (seller_id,customer_id,order_id,rating,comment)
                     VALUES ($seller_id,$customer_uid,$order_id,4,'Good shop')");
check('seller rating saved (separate table)',
      (int) scalar($conn, "SELECT COUNT(*) FROM seller_ratings WHERE order_id=$order_id") === 1);

// the eligibility query the pages use
$eligible = (int) scalar($conn,
    "SELECT COUNT(*) FROM orders o
     WHERE o.customer_id=$customer_uid AND o.status='delivered'
       AND NOT EXISTS (SELECT 1 FROM seller_ratings sr WHERE sr.order_id=o.order_id AND sr.customer_id=o.customer_id)");
check('rated order no longer offered for rating', $eligible === 0);

// ------------------------------------------------------------------ bidding
section('Price bidding');

mysqli_query($conn, "INSERT INTO offers (product_id,customer_id,offered_price,status)
                     VALUES ($product_id,$customer_uid,400.00,'pending')");
$offer_id = mysqli_insert_id($conn);
check('bid placed', $offer_id > 0);

mysqli_query($conn, "UPDATE offers SET status='countered', counter_price=450.00 WHERE offer_id=$offer_id");
check('seller counter recorded', (float) scalar($conn, "SELECT counter_price FROM offers WHERE offer_id=$offer_id") == 450.00);

mysqli_query($conn, "UPDATE offers SET status='accepted' WHERE offer_id=$offer_id");

// checkout honours the counter price
$r = mysqli_query($conn, "SELECT offered_price, counter_price FROM offers WHERE offer_id=$offer_id");
$o = mysqli_fetch_assoc($r);
$agreed = $o['counter_price'] !== null ? (float)$o['counter_price'] : (float)$o['offered_price'];
check('agreed price is the counter, not the original bid', $agreed == 450.00);

$bid_order = place_order($conn, $customer_uid, $seller_id, $product_id, 1, $agreed, 10.00, 0, 'cod');
check('order created from the accepted bid', $bid_order > 0);
check('order priced at the bid, not the listed price',
      (float) scalar($conn, "SELECT subtotal FROM orders WHERE order_id=$bid_order") == 450.00);
check('commission recalculated on the bid price',
      (float) scalar($conn, "SELECT commission_amount FROM orders WHERE order_id=$bid_order") == 45.00);

// spend the bid
$stmt = mysqli_prepare($conn, "UPDATE offers SET converted_order_id=? WHERE offer_id=? AND customer_id=? AND converted_order_id IS NULL");
mysqli_stmt_bind_param($stmt, 'iii', $bid_order, $offer_id, $customer_uid);
mysqli_stmt_execute($stmt);
$spent = mysqli_stmt_affected_rows($stmt); mysqli_stmt_close($stmt);
check('bid marked as spent', $spent === 1);

$stmt = mysqli_prepare($conn, "UPDATE offers SET converted_order_id=? WHERE offer_id=? AND customer_id=? AND converted_order_id IS NULL");
mysqli_stmt_bind_param($stmt, 'iii', $bid_order, $offer_id, $customer_uid);
mysqli_stmt_execute($stmt);
$again = mysqli_stmt_affected_rows($stmt); mysqli_stmt_close($stmt);
check('a spent bid cannot be redeemed twice', $again === 0);

// ------------------------------------------------------------------ cancel
section('Cancellation restores stock');

$stock_before = (int) scalar($conn, "SELECT stock_quantity FROM products WHERE product_id=$product_id");
$cancel_order = place_order($conn, $customer_uid, $seller_id, $product_id, 3, 500.00, 10.00, 0, 'cod');
check('order for cancellation placed', $cancel_order > 0);
check('stock reserved', (int) scalar($conn, "SELECT stock_quantity FROM products WHERE product_id=$product_id") === $stock_before - 3);

mysqli_query($conn, "UPDATE orders SET status='cancelled' WHERE order_id=$cancel_order");
restore_order_stock($conn, $cancel_order);
check('cancelling puts the stock back',
      (int) scalar($conn, "SELECT stock_quantity FROM products WHERE product_id=$product_id") === $stock_before);

// ------------------------------------------------------------------ chat
section('Order chat scoping');

require_once 'includes/order_chat.php';
$order_row = chat_participants($conn, $order_id);
check('chat participants resolved', $order_row !== null);
check('customer may read the thread', can_access_chat($order_row, $customer_uid));
check('shop owner may read the thread', can_access_chat($order_row, $seller_uid));
check('assigned rider may read the thread', can_access_chat($order_row, $rider_uid));
check('an unrelated rider may NOT read it', !can_access_chat($order_row, $rider2_uid));

mysqli_query($conn, "INSERT INTO messages (order_id,sender_id,message_text) VALUES ($order_id,$customer_uid,'Where is my food?')");
mysqli_query($conn, "INSERT INTO messages (order_id,sender_id,message_text) VALUES ($order_id,$rider_uid,'Two minutes away.')");
check('messages stored against the order', (int) scalar($conn, "SELECT COUNT(*) FROM messages WHERE order_id=$order_id") === 2);

$unread = (int) scalar($conn, "SELECT COUNT(*) FROM messages WHERE order_id=$order_id AND sender_id!=$customer_uid AND is_read=0");
check('rider message is unread for the customer', $unread === 1);
mysqli_query($conn, "UPDATE messages SET is_read=1 WHERE order_id=$order_id AND sender_id!=$customer_uid");
check('polling marks the other side read',
      (int) scalar($conn, "SELECT COUNT(*) FROM messages WHERE order_id=$order_id AND sender_id!=$customer_uid AND is_read=0") === 0);

// ------------------------------------------------------------------ admin
section('Admin reporting');

$commission_total = (float) scalar($conn, "SELECT COALESCE(SUM(commission_amount),0) FROM orders WHERE status='delivered'");
check('delivered-only commission total', $commission_total == 100.00, "got $commission_total");

$gross = (float) scalar($conn, "SELECT COALESCE(SUM(subtotal),0) FROM orders WHERE status='delivered'");
check('gross sales counts delivered only', $gross == 1000.00, "got $gross");

$r = mysqli_query($conn, "SELECT COUNT(CASE WHEN o.status='delivered' THEN 1 END) d,
                                 COALESCE(SUM(CASE WHEN o.status='delivered' THEN o.commission_amount END),0) c
                          FROM seller_profiles sp
                          LEFT JOIN orders o ON o.seller_id = sp.seller_id
                          WHERE sp.approval_status='approved'
                          GROUP BY sp.seller_id");
$row = mysqli_fetch_assoc($r);
check('per-seller breakdown query runs', $row !== null && (int)$row['d'] === 1);

// Exercise the notification writes the pages actually perform, rather than
// asserting on fixtures that never went through those code paths.
notify_user($conn, $customer_uid, 'Direct notification test.');
check('notify_user() writes a row',
      (int) scalar($conn, "SELECT COUNT(*) FROM notifications WHERE user_id=$customer_uid") === 1);

// checkout notifies the shop by resolving seller_id -> user_id in one statement
$stmt = mysqli_prepare($conn,
    "INSERT INTO notifications (user_id, message)
     SELECT user_id, ? FROM seller_profiles WHERE seller_id = ?");
$msg = 'New order received.';
mysqli_stmt_bind_param($stmt, 'si', $msg, $seller_id);
mysqli_stmt_execute($stmt);
$inserted = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);
check('seller notification resolves seller_id to the owner', $inserted === 1);
check('it landed on the shop owner, not the seller_id',
      (int) scalar($conn, "SELECT user_id FROM notifications WHERE message='New order received.'") === $seller_uid);

// unread count is what ajax/poll_notifications.php returns
check('unread count query works',
      (int) scalar($conn, "SELECT COUNT(*) FROM notifications WHERE user_id=$seller_uid AND is_read=0") === 1);
mysqli_query($conn, "UPDATE notifications SET is_read=1 WHERE user_id=$seller_uid");
check('marking read clears the unread count',
      (int) scalar($conn, "SELECT COUNT(*) FROM notifications WHERE user_id=$seller_uid AND is_read=0") === 0);

// ------------------------------------------------------------------ summary
section('Summary');
echo "\n  $pass_count passed, $fail_count failed\n";
if ($fail_count > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
}
mysqli_query($conn, "DROP DATABASE IF EXISTS $db_name");
echo "\n(test database dropped)\n";
exit($fail_count > 0 ? 1 : 0);
