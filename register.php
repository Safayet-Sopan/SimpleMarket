<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

$allowed_roles = ['customer', 'seller', 'rider'];

$nameErr = $emailErr = $phoneErr = $passwordErr = $confirmErr = $roleErr = $shopErr = "";
$full_name = $email = $phone = $role = $shop_name = $vehicle_type = "";
$isValid = false;

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Full name
    if (empty($_POST["full_name"])) {
        $nameErr = "Name is required";
    } else {
        $full_name = cleanInput($_POST["full_name"]);
        if (!preg_match("/^[a-zA-Z-' ]*$/", $full_name)) {
            $nameErr = "Only letters and white spaces are allowed.";
        }
    }

    // Email
    if (empty($_POST["email"])) {
        $emailErr = "Email cannot be empty";
    } else {
        $email = cleanInput($_POST["email"]);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emailErr = "Invalid email format";
        } else {
            $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE email = ?");
            mysqli_stmt_bind_param($stmt, 's', $email);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            if (mysqli_stmt_num_rows($stmt) > 0) {
                $emailErr = "That email is already registered";
            }
            mysqli_stmt_close($stmt);
        }
    }

    // Phone (optional, but if given must look like digits)
    if (!empty($_POST["phone"])) {
        $phone = cleanInput($_POST["phone"]);
        if (!preg_match("/^[0-9+\- ]{7,20}$/", $phone)) {
            $phoneErr = "Invalid phone number";
        }
    }

    // Password
    // not run through cleanInput — don't htmlspecialchars a password
    if (empty($_POST["password"])) {
        $passwordErr = "Password cannot be empty";
    } else {
        $password = $_POST["password"];
        if (strlen($password) < 8) {
            $passwordErr = "Password must be at least 8 characters";
        } else if (!preg_match("/[A-Za-z]/", $password) || !preg_match("/[0-9]/", $password)) {
            $passwordErr = "Password must contain at least one letter and one number";
        }
    }

    // Confirm password
    if (empty($_POST["confirm_password"])) {
        $confirmErr = "Please confirm your password";
    } else if (!$passwordErr && $_POST["confirm_password"] !== $password) {
        $confirmErr = "Passwords do not match";
    }

    // Role
    if (empty($_POST["role"])) {
        $roleErr = "Must select a role";
    } else {
        $role = cleanInput($_POST["role"]);
        if (!in_array($role, $allowed_roles, true)) {
            $roleErr = "Invalid role selected";
        }
    }

    // Role-specific fields
    if ($role === 'seller') {
        if (empty($_POST["shop_name"])) {
            $shopErr = "Shop name is required for sellers";
        } else {
            $shop_name = cleanInput($_POST["shop_name"]);
        }
    } elseif ($role === 'rider') {
        $vehicle_type = cleanInput($_POST["vehicle_type"] ?? '');
    }

    if (!$nameErr && !$emailErr && !$phoneErr && !$passwordErr && !$confirmErr && !$roleErr && !$shopErr) {
        $isValid = true;
    }

    if ($isValid) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $status = ($role === 'seller') ? 'pending' : 'active';

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO users (full_name, email, phone, password_hash, role, status) VALUES (?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($stmt, 'ssssss', $full_name, $email, $phone, $password_hash, $role, $status);

        if (mysqli_stmt_execute($stmt)) {
            $user_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            if ($role === 'seller') {
                $stmt2 = mysqli_prepare(
                    $conn,
                    "INSERT INTO seller_profiles (user_id, shop_name, approval_status) VALUES (?, ?, 'pending')"
                );
                mysqli_stmt_bind_param($stmt2, 'is', $user_id, $shop_name);
                mysqli_stmt_execute($stmt2);
                mysqli_stmt_close($stmt2);
            } elseif ($role === 'rider') {
                $stmt2 = mysqli_prepare(
                    $conn,
                    "INSERT INTO rider_profiles (user_id, vehicle_type, availability_status) VALUES (?, ?, 'offline')"
                );
                mysqli_stmt_bind_param($stmt2, 'is', $user_id, $vehicle_type);
                mysqli_stmt_execute($stmt2);
                mysqli_stmt_close($stmt2);
            }

            session_start();
            $_SESSION['flash'] = ['message' => 'Registration successful. Please log in.', 'type' => 'success'];
            header('Location: login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Register — SimpleMarket</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <h1>Register</h1>

    <form method="POST" action="register.php" id="register-form">
        <input type="text" name="full_name" placeholder="Full Name" value="<?php echo $full_name; ?>">
        <span class="error"><?php echo $nameErr; ?></span>

        <input type="text" name="email" placeholder="Email" value="<?php echo $email; ?>">
        <span class="error"><?php echo $emailErr; ?></span>

        <input type="text" name="phone" placeholder="Phone" value="<?php echo $phone; ?>">
        <span class="error"><?php echo $phoneErr; ?></span>

        <input type="password" name="password" placeholder="Password">
        <span class="error"><?php echo $passwordErr; ?></span>

        <input type="password" name="confirm_password" placeholder="Confirm Password">
        <span class="error"><?php echo $confirmErr; ?></span>

        <select name="role" id="role-select">
            <option value="">Select Role</option>
            <option value="customer" <?php if ($role == "customer") echo "selected"; ?>>Customer</option>
            <option value="seller" <?php if ($role == "seller") echo "selected"; ?>>Seller</option>
            <option value="rider" <?php if ($role == "rider") echo "selected"; ?>>Rider</option>
        </select>
        <span class="error"><?php echo $roleErr; ?></span>

        <div id="seller-fields" style="display:none;">
            <input type="text" name="shop_name" placeholder="Shop Name" value="<?php echo $shop_name; ?>">
            <span class="error"><?php echo $shopErr; ?></span>
        </div>

        <div id="rider-fields" style="display:none;">
            <input type="text" name="vehicle_type" placeholder="Vehicle Type" value="<?php echo $vehicle_type; ?>">
        </div>

        <button type="submit">Register</button>
    </form>

    <p>Already have an account? <a href="login.php">Login</a></p>

    <script src="assets/js/validation.js"></script>
</body>

</html>