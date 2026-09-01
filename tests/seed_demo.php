<?php
// Creates demo accounts + sample data in the REAL database so the HTTP smoke
// test has something to log into. Idempotent: skips anything already present.
// All demo accounts use the password  Passw0rd!
chdir('/Applications/XAMPP/xamppfiles/htdocs/SimpleMarket');
require_once 'includes/db.php';
/** @var mysqli $conn */

function upsert_user($conn, $name, $email, $role, $status) {
    $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE email = ?");
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($row) { echo "  = $email already exists (id {$row['user_id']})\n"; return $row['user_id']; }

    $hash = password_hash('Passw0rd!', PASSWORD_DEFAULT);
    $phone = '01700000000';
    $stmt = mysqli_prepare($conn, "INSERT INTO users (full_name,email,phone,password_hash,role,status) VALUES (?,?,?,?,?,?)");
    mysqli_stmt_bind_param($stmt, 'ssssss', $name, $email, $phone, $hash, $role, $status);
    mysqli_stmt_execute($stmt);
    $id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    echo "  + created $email ($role, id $id)\n";
    return $id;
}

echo "Demo accounts (password: Passw0rd!)\n";
$admin_uid    = upsert_user($conn, 'Demo Admin',    'admin@demo.local',  'admin',    'active');
$seller_uid   = upsert_user($conn, 'Demo Shop',     'seller@demo.local', 'seller',   'active');
$customer_uid = upsert_user($conn, 'Demo Buyer',    'buyer@demo.local',  'customer', 'active');
$rider_uid    = upsert_user($conn, 'Demo Rider',    'rider@demo.local',  'rider',    'active');

// seller profile
$r = mysqli_query($conn, "SELECT seller_id FROM seller_profiles WHERE user_id=$seller_uid");
if ($row = mysqli_fetch_assoc($r)) { $seller_id = $row['seller_id']; echo "  = seller profile exists\n"; }
else {
    mysqli_query($conn, "INSERT INTO seller_profiles (user_id,shop_name,shop_address,business_type,commission_rate,approval_status,payment_methods)
                         VALUES ($seller_uid,'Demo Kitchen','Dhanmondi, Dhaka','Home Food',10.00,'approved','cod,bkash')");
    $seller_id = mysqli_insert_id($conn); echo "  + seller profile created\n";
}

// rider profile
$r = mysqli_query($conn, "SELECT rider_id FROM rider_profiles WHERE user_id=$rider_uid");
if ($row = mysqli_fetch_assoc($r)) { $rider_id = $row['rider_id']; echo "  = rider profile exists\n"; }
else {
    mysqli_query($conn, "INSERT INTO rider_profiles (user_id,vehicle_type,vehicle_plate,vehicle_capacity,availability_status)
                         VALUES ($rider_uid,'Motorbike','DHA-2024','15kg','available')");
    $rider_id = mysqli_insert_id($conn); echo "  + rider profile created\n";
}

// a product
$r = mysqli_query($conn, "SELECT product_id FROM products WHERE seller_id=$seller_id LIMIT 1");
if ($row = mysqli_fetch_assoc($r)) { $product_id = $row['product_id']; echo "  = product exists\n"; }
else {
    $cat = mysqli_fetch_assoc(mysqli_query($conn, "SELECT category_id FROM categories LIMIT 1"));
    $cat_id = $cat ? $cat['category_id'] : 'NULL';
    mysqli_query($conn, "INSERT INTO products (seller_id,category_id,product_name,description,price,stock_quantity,low_stock_threshold,status)
                         VALUES ($seller_id,$cat_id,'Beef Tehari','Family pack, serves 4',500.00,20,5,'active')");
    $product_id = mysqli_insert_id($conn); echo "  + product created\n";
}

// one order in each interesting state, so every page has something to render
$existing = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM orders WHERE customer_id=$customer_uid"))['c'];
if ($existing > 0) {
    echo "  = orders already present ($existing)\n";
} else {
    // pending
    mysqli_query($conn, "INSERT INTO orders (customer_id,seller_id,delivery_address,fast_delivery,delivery_fee,subtotal,commission_amount,total_amount,payment_method,status)
                         VALUES ($customer_uid,$seller_id,'Road 5, Dhanmondi',0,30.00,500.00,50.00,530.00,'cod','pending')");
    // preparing, unclaimed -> visible to riders
    mysqli_query($conn, "INSERT INTO orders (customer_id,seller_id,delivery_address,fast_delivery,delivery_fee,subtotal,commission_amount,total_amount,payment_method,status)
                         VALUES ($customer_uid,$seller_id,'Road 9, Dhanmondi',1,70.00,1000.00,100.00,1070.00,'bkash','preparing')");
    // delivered by the demo rider -> unlocks review + rating + earnings
    mysqli_query($conn, "INSERT INTO orders (customer_id,seller_id,rider_id,delivery_address,fast_delivery,delivery_fee,subtotal,commission_amount,total_amount,payment_method,payment_status,status)
                         VALUES ($customer_uid,$seller_id,$rider_id,'Road 12, Dhanmondi',0,30.00,500.00,50.00,530.00,'cod','paid','delivered')");
    $delivered_id = mysqli_insert_id($conn);
    mysqli_query($conn, "INSERT INTO order_items (order_id,product_id,quantity,unit_price,line_total) VALUES ($delivered_id,$product_id,1,500.00,500.00)");
    mysqli_query($conn, "INSERT INTO earnings (rider_id,order_id,amount) VALUES ($rider_id,$delivered_id,24.00)");
    echo "  + 3 orders created (pending, preparing, delivered)\n";
}

// a pending bid so price_bidding.php has a row
$bids = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM offers WHERE customer_id=$customer_uid"))['c'];
if ($bids == 0) {
    mysqli_query($conn, "INSERT INTO offers (product_id,customer_id,offered_price,status) VALUES ($product_id,$customer_uid,400.00,'pending')");
    echo "  + 1 pending bid created\n";
} else { echo "  = bids already present\n"; }

// a notification each, so notifications.php is not empty
foreach ([$admin_uid,$seller_uid,$customer_uid,$rider_uid] as $uid) {
    $n = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM notifications WHERE user_id=$uid"))['c'];
    if ($n == 0) {
        $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id,message) VALUES (?,?)");
        $msg = 'Welcome to SimpleMarket.';
        mysqli_stmt_bind_param($stmt, 'is', $uid, $msg);
        mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt);
    }
}
echo "\nDone.\n";
