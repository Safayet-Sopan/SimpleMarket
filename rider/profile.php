<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('rider');

$nameErr = $phoneErr = $vehicleTypeErr = $vehiclePlateErr = $vehicleCapacityErr = "";
$successMsg = "";

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

$user_id = $_SESSION['user_id'];

// Fetch current user + rider profile data (joined)
$stmt = mysqli_prepare(
    $conn,
    "SELECT u.full_name, u.email, u.phone, rp.rider_id, rp.vehicle_type, rp.vehicle_plate, rp.vehicle_capacity, rp.availability_status
     FROM users u
     JOIN rider_profiles rp ON rp.user_id = u.user_id
     WHERE u.user_id = ?"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$data = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

$full_name = $data['full_name'];
$phone = $data['phone'];
$vehicle_type = $data['vehicle_type'];
$vehicle_plate = $data['vehicle_plate'];
$vehicle_capacity = $data['vehicle_capacity'];
$rider_id = $data['rider_id'];

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

    // Phone (optional)
    $phone = cleanInput($_POST["phone"] ?? '');
    if ($phone !== '' && !preg_match("/^[0-9+\- ]{7,20}$/", $phone)) {
        $phoneErr = "Invalid phone number";
    }

    // Vehicle type
    if (empty($_POST["vehicle_type"])) {
        $vehicleTypeErr = "Vehicle type is required";
    } else {
        $vehicle_type = cleanInput($_POST["vehicle_type"]);
    }

    // Vehicle plate
    $vehicle_plate = cleanInput($_POST["vehicle_plate"] ?? '');
    if ($vehicle_plate !== '' && !preg_match("/^[a-zA-Z0-9\- ]{2,50}$/", $vehicle_plate)) {
        $vehiclePlateErr = "Invalid plate format";
    }

    // Vehicle capacity (optional, free text e.g. "20kg", "2 seats")
    $vehicle_capacity = cleanInput($_POST["vehicle_capacity"] ?? '');
    if (strlen($vehicle_capacity) > 50) {
        $vehicleCapacityErr = "Too long";
    }

    if (!$nameErr && !$phoneErr && !$vehicleTypeErr && !$vehiclePlateErr && !$vehicleCapacityErr) {
        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn, "UPDATE users SET full_name = ?, phone = ? WHERE user_id = ?");
            mysqli_stmt_bind_param($stmt, 'ssi', $full_name, $phone, $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "UPDATE rider_profiles SET vehicle_type = ?, vehicle_plate = ?, vehicle_capacity = ? WHERE rider_id = ?");
            mysqli_stmt_bind_param($stmt, 'sssi', $vehicle_type, $vehicle_plate, $vehicle_capacity, $rider_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            mysqli_commit($conn);
            $successMsg = "Profile updated successfully.";
            $_SESSION['full_name'] = $full_name;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $successMsg = "";
            $vehicleTypeErr = "Something went wrong. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Profile — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/rider.css">
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
        <input type="text" value="<?php echo htmlspecialchars($data['email']); ?>" disabled>

        <label>Phone</label>
        <input type="text" name="phone" value="<?php echo htmlspecialchars($phone); ?>">
        <span class="error"><?php echo $phoneErr; ?></span>

        <hr>

        <label>Vehicle Type</label>
        <input type="text" name="vehicle_type" placeholder="e.g. Motorcycle, Bicycle, Van" value="<?php echo htmlspecialchars($vehicle_type); ?>">
        <span class="error"><?php echo $vehicleTypeErr; ?></span>

        <label>Vehicle Plate</label>
        <input type="text" name="vehicle_plate" value="<?php echo htmlspecialchars($vehicle_plate); ?>">
        <span class="error"><?php echo $vehiclePlateErr; ?></span>

        <label>Vehicle Capacity</label>
        <input type="text" name="vehicle_capacity" placeholder="e.g. 20kg, 2 boxes" value="<?php echo htmlspecialchars($vehicle_capacity); ?>">
        <span class="error"><?php echo $vehicleCapacityErr; ?></span>

        <label>Availability Status</label>
        <input type="text" value="<?php echo htmlspecialchars($data['availability_status']); ?>" disabled>

        <button type="submit">Save Changes</button>
    </form>

    <a href="change_password.php">Change Password</a>
    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>