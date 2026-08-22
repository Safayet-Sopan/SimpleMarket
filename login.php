<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

$emailErr = $passwordErr = $loginErr = "";
$email = "";

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Email
    if (empty($_POST["email"])) {
        $emailErr = "Email cannot be empty";
    } else {
        $email = cleanInput($_POST["email"]);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emailErr = "Invalid email format";
        }
    }

    // Password
    if (empty($_POST["password"])) {
        $passwordErr = "Password cannot be empty";
    } else {
        $password = $_POST["password"]; // not cleaned — don't htmlspecialchars a password
    }

    if (!$emailErr && !$passwordErr) {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT user_id, full_name, password_hash, role, status FROM users WHERE email = ?"
        );
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $loginErr = "Invalid email or password";
        } elseif ($user['status'] === 'suspended') {
            $loginErr = "This account has been suspended";
        } elseif ($user['status'] === 'pending') {
            $loginErr = "Your seller application is still pending approval";
        } else {
            $_SESSION['user_id']   = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];

            header('Location: ' . $user['role'] . '/dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Login — SimpleMarket</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <h1>Login</h1>

    <?php if ($flash): ?>
        <p class="<?php echo $flash['type']; ?>"><?php echo $flash['message']; ?></p>
    <?php endif; ?>

    <?php if ($loginErr): ?>
        <p class="error"><?php echo $loginErr; ?></p>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <input type="text" name="email" placeholder="Email" value="<?php echo $email; ?>">
        <span class="error"><?php echo $emailErr; ?></span>

        <input type="password" name="password" placeholder="Password">
        <span class="error"><?php echo $passwordErr; ?></span>

        <button type="submit">Login</button>
    </form>

    <p>Don't have an account? <a href="register.php">Register</a></p>
</body>

</html>