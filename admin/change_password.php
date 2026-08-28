<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('admin');

$currentErr = $newErr = $confirmErr = "";
$successMsg = "";

$user_id = $_SESSION['user_id'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $current_password = $_POST["current_password"] ?? '';
    $new_password      = $_POST["new_password"] ?? '';
    $confirm_password  = $_POST["confirm_password"] ?? '';
    // passwords are NOT run through cleanInput/htmlspecialchars — that would corrupt special characters

    // Fetch current hash
    /** @var mysqli $conn */
    $stmt = mysqli_prepare($conn, "SELECT password_hash FROM users WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    // Current password check
    if (empty($current_password)) {
        $currentErr = "Current password is required";
    } elseif (!password_verify($current_password, $row['password_hash'])) {
        $currentErr = "Current password is incorrect";
    }

    // New password
    if (empty($new_password)) {
        $newErr = "New password is required";
    } elseif (strlen($new_password) < 8) {
        $newErr = "Password must be at least 8 characters";
    } elseif (!preg_match("/[A-Za-z]/", $new_password) || !preg_match("/[0-9]/", $new_password)) {
        $newErr = "Password must contain at least one letter and one number";
    } elseif (!$currentErr && password_verify($new_password, $row['password_hash'])) {
        $newErr = "New password must be different from current password";
    }

    // Confirm password
    if (empty($confirm_password)) {
        $confirmErr = "Please confirm your new password";
    } elseif (!$newErr && $confirm_password !== $new_password) {
        $confirmErr = "Passwords do not match";
    }

    if (!$currentErr && !$newErr && !$confirmErr) {
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "UPDATE users SET password_hash = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $new_hash, $user_id);
        if (mysqli_stmt_execute($stmt)) {
            $successMsg = "Password changed successfully.";
        }
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Change Password — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body>
    <h1>Change Password</h1>

    <?php if ($successMsg): ?>
        <p class="success"><?php echo $successMsg; ?></p>
    <?php endif; ?>

    <form method="POST" action="change_password.php">
        <label>Current Password</label>
        <input type="password" name="current_password">
        <span class="error"><?php echo $currentErr; ?></span>

        <label>New Password</label>
        <input type="password" name="new_password">
        <span class="error"><?php echo $newErr; ?></span>

        <label>Confirm New Password</label>
        <input type="password" name="confirm_password">
        <span class="error"><?php echo $confirmErr; ?></span>

        <button type="submit">Update Password</button>
    </form>

    <a href="profile.php">Back to Profile</a>
    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>