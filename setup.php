<?php
// setup.php — one-time database bootstrap.
// Run this once in the browser after cloning the repo. Safe to run multiple
// times: every statement uses IF NOT EXISTS, so nothing gets overwritten
// or duplicated if you accidentally run it again.

$host = 'localhost';
$user = 'root';
$pass = '';
$db_name = 'simplemarket_db';

// Connect without selecting a database yet, since it might not exist
$conn = mysqli_connect($host, $user, $pass);
if (!$conn) {
    die('Connection failed: ' . mysqli_connect_error());
}

// Create the database if it doesn't exist
$sql = "CREATE DATABASE IF NOT EXISTS $db_name CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
if (!mysqli_query($conn, $sql)) {
    die('Error creating database: ' . mysqli_error($conn));
}

// Now select it
mysqli_select_db($conn, $db_name);

// All CREATE TABLE statements, in FK-safe order
$tables = [];

$tables['users'] = "
    CREATE TABLE IF NOT EXISTS users (
        user_id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        phone VARCHAR(20),
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin','seller','customer','rider') NOT NULL,
        status ENUM('active','pending','suspended') NOT NULL DEFAULT 'active',
        profile_image VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
";

$tables['categories'] = "
    CREATE TABLE IF NOT EXISTS categories (
        category_id INT AUTO_INCREMENT PRIMARY KEY,
        category_name VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB
";

$tables['seller_profiles'] = "
    CREATE TABLE IF NOT EXISTS seller_profiles (
        seller_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        shop_name VARCHAR(150) NOT NULL,
        shop_address VARCHAR(255),
        business_type VARCHAR(100),
        commission_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00,
        approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB
";

$tables['rider_profiles'] = "
    CREATE TABLE IF NOT EXISTS rider_profiles (
        rider_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        vehicle_type VARCHAR(50),
        vehicle_plate VARCHAR(50),
        vehicle_capacity VARCHAR(50),
        availability_status ENUM('available','busy','offline') NOT NULL DEFAULT 'offline',
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB
";

$tables['products'] = "
    CREATE TABLE IF NOT EXISTS products (
        product_id INT AUTO_INCREMENT PRIMARY KEY,
        seller_id INT NOT NULL,
        category_id INT,
        product_name VARCHAR(150) NOT NULL,
        description TEXT,
        price DECIMAL(10,2) NOT NULL,
        stock_quantity INT NOT NULL DEFAULT 0,
        low_stock_threshold INT NOT NULL DEFAULT 5,
        product_image VARCHAR(255),
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (seller_id) REFERENCES seller_profiles(seller_id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE SET NULL
    ) ENGINE=InnoDB
";

$tables['orders'] = "
    CREATE TABLE IF NOT EXISTS orders (
        order_id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        seller_id INT NOT NULL,
        rider_id INT,
        delivery_address VARCHAR(255) NOT NULL,
        fast_delivery TINYINT(1) NOT NULL DEFAULT 0,
        delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
        subtotal DECIMAL(10,2) NOT NULL,
        commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_amount DECIMAL(10,2) NOT NULL,
        status ENUM('pending','confirmed','preparing','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (seller_id) REFERENCES seller_profiles(seller_id) ON DELETE CASCADE,
        FOREIGN KEY (rider_id) REFERENCES rider_profiles(rider_id) ON DELETE SET NULL
    ) ENGINE=InnoDB
";

$tables['order_items'] = "
    CREATE TABLE IF NOT EXISTS order_items (
        order_item_id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        line_total DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE
    ) ENGINE=InnoDB
";

$tables['offers'] = "
    CREATE TABLE IF NOT EXISTS offers (
        offer_id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        customer_id INT NOT NULL,
        offered_price DECIMAL(10,2) NOT NULL,
        counter_price DECIMAL(10,2),
        status ENUM('pending','accepted','countered','rejected') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
        FOREIGN KEY (customer_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB
";

$tables['reviews'] = "
    CREATE TABLE IF NOT EXISTS reviews (
        review_id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        customer_id INT NOT NULL,
        order_id INT NOT NULL,
        rating TINYINT NOT NULL,
        comment TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
        FOREIGN KEY (customer_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
    ) ENGINE=InnoDB
";

$tables['seller_ratings'] = "
    CREATE TABLE IF NOT EXISTS seller_ratings (
        rating_id INT AUTO_INCREMENT PRIMARY KEY,
        seller_id INT NOT NULL,
        customer_id INT NOT NULL,
        order_id INT NOT NULL,
        rating TINYINT NOT NULL,
        comment TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (seller_id) REFERENCES seller_profiles(seller_id) ON DELETE CASCADE,
        FOREIGN KEY (customer_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
    ) ENGINE=InnoDB
";

$tables['messages'] = "
    CREATE TABLE IF NOT EXISTS messages (
        message_id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        sender_id INT NOT NULL,
        message_text TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
        FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB
";

$tables['earnings'] = "
    CREATE TABLE IF NOT EXISTS earnings (
        earning_id INT AUTO_INCREMENT PRIMARY KEY,
        rider_id INT NOT NULL,
        order_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        earned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (rider_id) REFERENCES rider_profiles(rider_id) ON DELETE CASCADE,
        FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
    ) ENGINE=InnoDB
";

$tables['notifications'] = "
    CREATE TABLE IF NOT EXISTS notifications (
        notification_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB
";

$results = [];
foreach ($tables as $table_name => $sql) {
    if (mysqli_query($conn, $sql)) {
        $results[$table_name] = 'OK';
    } else {
        $results[$table_name] = 'FAILED: ' . mysqli_error($conn);
    }
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>SimpleMarket — Setup</title>
</head>

<body>
    <h1>Database Setup</h1>
    <p>Database "<?php echo htmlspecialchars($db_name); ?>" ready.</p>
    <ul>
        <?php foreach ($results as $table => $status): ?>
            <li><?php echo htmlspecialchars($table); ?>: <?php echo htmlspecialchars($status); ?></li>
        <?php endforeach; ?>
    </ul>
    <p>If everything above says "OK", setup is complete. You can now go to <a href="index.php">the homepage</a>.</p>
</body>

</html>