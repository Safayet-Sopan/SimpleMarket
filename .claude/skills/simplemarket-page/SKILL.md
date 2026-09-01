---
name: simplemarket-page
description: Scaffold a new role page in SimpleMarket (admin/seller/customer/rider) with the correct auth guard, profile lookup, validation, prepared statements and navigation wiring. Use when adding any new page to this project.
---

# Adding a page to SimpleMarket

Follow `simplemarket-conventions` for code style. This skill is the checklist.

## 1. Pick the directory

`admin/`, `seller/`, `customer/` or `rider/`. The role directory determines the auth guard and
which CSS file the page links.

## 2. The skeleton

```php
<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../config.php';                 // only if you need the constants
require_role('seller');                       // matches the directory

/** @var mysqli $conn */

function cleanInput($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

$user_id = $_SESSION['user_id'];
```

The `/** @var mysqli $conn */` comment keeps editors from flagging `$conn`. Keep it.

## 3. Seller and rider pages need the profile id first

`$_SESSION['user_id']` is a **users** row. Most tables key off `seller_id` / `rider_id`:

```php
$stmt = mysqli_prepare($conn, "SELECT seller_id, shop_name FROM seller_profiles WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$seller = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
$seller_id = $seller['seller_id'] ?? null;
```

Then guard the page body on `if (!$seller_id)` with an error message — a seller account can
exist without a profile row.

## 4. Scope every query to the logged-in user

This is the main security property of the app. Never trust an id from the URL alone:

```php
// right: ownership is part of the WHERE clause
"SELECT ... FROM orders WHERE order_id = ? AND customer_id = ?"

// wrong: any customer can read any order by changing the URL
"SELECT ... FROM orders WHERE order_id = ?"
```

For seller-owned data, join through the profile: `... JOIN products p ON ... WHERE p.seller_id = ?`

## 5. POST handling

One error variable per field, then a single `if (!$err1 && !$err2)` before the write.
Multi-table writes go in a transaction. State changes use the guarded-UPDATE +
`affected_rows` pattern from `simplemarket-conventions`.

Notify the other party when a state change affects them:

```php
$stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message) VALUES (?, ?)");
```

or, when you only have a `seller_id`, resolve it in the statement:

```php
"INSERT INTO notifications (user_id, message)
 SELECT user_id, ? FROM seller_profiles WHERE seller_id = ?"
```

`includes/order_status.php` already provides `notify_user($conn, $user_id, $message)`.

## 6. The HTML tail

```php
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Page Name — SimpleMarket</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/seller.css">
</head>

<body>
    <h1>Page Name</h1>

    <?php if ($successMsg): ?>
        <p class="success"><?php echo htmlspecialchars($successMsg); ?></p>
    <?php endif; ?>
    <?php if ($actionErr): ?>
        <p class="error"><?php echo htmlspecialchars($actionErr); ?></p>
    <?php endif; ?>

    <!-- tables use: <table border="1" cellpadding="8"> -->

    <a href="dashboard.php">Back to Dashboard</a>
</body>

</html>
```

No CSS. Reuse the existing class names.

## 7. Wire it up — easy to forget

- Add a link from that role's `dashboard.php`
- Add links from related pages (an orders page should link to chat and tracking)
- If it is a serious feature, give the dashboard a `stat-card` with a live count

## 8. Verify

```bash
php -l seller/your_page.php
python3 tests/bindcheck.py
python3 tests/linkcheck.py        # catches a link pointed at a file that does not exist
```

Then add it to `http_smoke.sh`'s page list for that role and run the suite. See
`simplemarket-test`.

## 9. Update progress.md

`progress.md` is the project handoff document. Add the file to the §6 tree and update the status
sections. It is the first thing the next session reads — a stale entry there has already caused
real confusion (three files were marked done while being 0 bytes).
