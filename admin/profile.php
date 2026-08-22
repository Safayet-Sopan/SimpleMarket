<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('admin');

$nameErr = $phoneErr = "";
$successMsg = "";

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

$user_id = $_SESSION['user_id'];

// Fetch current user data
/** @var mysqli $conn */
$stmt = mysqli_prepare($conn, "SELECT password_hash FROM users WHERE user_id = ?");
$stmt = mysqli_prepare($conn, "SELECT full_name, email, phone FROM users WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

$full_name = $user['full_name'];
$phone = $user['phone'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Name
    if (empty($_POST["full_name"])) {
        $nameErr = "Name is required";
    } else {
        $full_name = cleanInput($_POST["full_name"]);
        if (!preg_match("/^[a-zA-Z-' ]*$/", $full_name)) {
            $nameErr = "Only letters and white spaces are allowed.";
        }
    }

    // Phone (optional)
    $phone = cleanInput($_POST["phone"] ?? '');
    if ($phone !== '' && !preg_match("/^[0-9+\- ]{7,20}$/", $phone)) {
        $phoneErr = "Invalid phone number";
    }

    if (!$nameErr && !$phoneErr) {
        $stmt = mysqli_prepare($conn, "UPDATE users SET full_name = ?, phone = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, 'ssi', $full_name, $phone, $user_id);
        if (mysqli_stmt_execute($stmt)) {
            $successMsg = "Profile updated successfully.";
            $_SESSION['full_name'] = $full_name;
        }
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Profile — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body>
    <h1>My Profile</h1>

    <?php if ($successMsg): ?>
        <p class="success"><?php echo $successMsg; ?></p>
    <?php endif; ?>

    <form method="POST" action="profile.php">
        <label>Full Name</label>
        <input type="text" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>">
        <span class="error"><?php echo $nameErr; ?></span>

        <label>Email (cannot be changed)</label>
        <input type="text" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>

        <label>Phone</label>
        <input type="text" name="phone" value="<?php echo htmlspecialchars($phone); ?>">
        <span class="error"><?php echo $phoneErr; ?></span>

        <button type="submit">Save Changes</button>
    </form>

    <a href="change_password.php">Change Password</a>
    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>