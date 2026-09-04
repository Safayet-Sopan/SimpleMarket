<?php
// The one database connection the whole app uses. Every model reads $conn from
// here; nothing else opens a connection.
//
// mysqli PROCEDURAL throughout — not the mysqli:: object API, not PDO.

if (!isset($db_host)) { $db_host = 'localhost'; }
if (!isset($db_user)) { $db_user = 'root'; }
if (!isset($db_pass)) { $db_pass = ''; }
// setup.php and the test harness can point this at a throwaway database by
// setting $db_name before including this file.
if (!isset($db_name)) { $db_name = 'simplemarket_db'; }

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

if (!$conn) {
    die('Connection failed: ' . mysqli_connect_error());
}

mysqli_set_charset($conn, 'utf8mb4');
