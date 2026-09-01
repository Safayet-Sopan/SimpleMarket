<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

// This page creates an account with full admin rights, so it is only open while
// no admin exists yet. Once one does, only a logged-in admin may add another.
/** @var mysqli $conn */
$admin_exists = false;
$result = mysqli_query($conn, "SELECT user_id FROM users WHERE role = 'admin' LIMIT 1");
if ($result && mysqli_fetch_assoc($result)) {
    $admin_exists = true;
}

if ($admin_exists && current_role() !== 'admin') {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<title>Not Available — SimpleMarket</title></head><body>'
        . '<h1>Admin Setup Closed</h1>'
        . '<p class="error">An admin account already exists. Sign in as an admin to add another.</p>'
        . '<p><a href="login.php">Go to login</a></p>'
        . '</body></html>';
    exit;
}

$nameErr = $emailErr = $passwordErr = "";
$successMsg = "";

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $full_name = cleanInput($_POST["full_name"] ?? '');
    $email     = cleanInput($_POST["email"] ?? '');
    $password  = $_POST["password"] ?? ''; // not cleaned — don't htmlspecialchars a password

    if (empty($full_name)) {
        $nameErr = "Name is required";
    }

    if (empty($email)) {
        $emailErr = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $emailErr = "Invalid email format";
    } else {
        $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        if (mysqli_stmt_num_rows($stmt) > 0) {
            $emailErr = "An account with that email already exists";
        }
        mysqli_stmt_close($stmt);
    }

    if (empty($password)) {
        $passwordErr = "Password is required";
    } elseif (strlen($password) < 8) {
        $passwordErr = "Password must be at least 8 characters";
    } elseif (!preg_match("/[A-Za-z]/", $password) || !preg_match("/[0-9]/", $password)) {
        $passwordErr = "Password must contain at least one letter and one number";
    }

    if (!$nameErr && !$emailErr && !$passwordErr) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO users (full_name, email, password_hash, role, status) VALUES (?, ?, ?, 'admin', 'active')"
        );
        mysqli_stmt_bind_param($stmt, 'sss', $full_name, $email, $password_hash);

        if (mysqli_stmt_execute($stmt)) {
            $successMsg = "Admin account created. You can now log in.";
        } else {
            $passwordErr = "Something went wrong. Please try again.";
        }
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Create Admin — SimpleMarket</title>
</head>

<body>
    <h1>Create Admin Account</h1>
    <p>Run this once per machine to create your local admin login.</p>

    <?php if ($successMsg): ?>
        <p style="color:green;"><?php echo $successMsg; ?></p>
        <a href="login.php">Go to Login</a>
    <?php else: ?>
        <form method="POST" action="create_admin.php">
            <label>Full Name</label>
            <input type="text" name="full_name" value="<?php echo htmlspecialchars($full_name ?? ''); ?>">
            <span style="color:red;"><?php echo $nameErr; ?></span>

            <label>Email</label>
            <input type="text" name="email" value="<?php echo htmlspecialchars($email ?? ''); ?>">
            <span style="color:red;"><?php echo $emailErr; ?></span>

            <label>Password</label>
            <input type="password" name="password">
            <span style="color:red;"><?php echo $passwordErr; ?></span>

            <button type="submit">Create Admin</button>
        </form>
    <?php endif; ?>
</body>

</html>