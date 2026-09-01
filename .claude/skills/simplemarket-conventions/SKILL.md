---
name: simplemarket-conventions
description: The stack rules and code patterns for the SimpleMarket PHP marketplace. Load this before writing or editing any PHP in this project — it covers the strict procedural/mysqli constraints, the validation style, the security rules, and the bug classes this codebase has actually hit.
---

# SimpleMarket conventions

A mini multi-vendor marketplace. Four roles: **admin**, **seller**, **customer**, **rider**.

Read `progress.md` in the project root for current feature status and the schema. This skill is
about *how to write the code*.

## Stack constraints — hard rules, not preferences

- **PHP, procedural only.** No classes, no OOP, no Composer, no autoloading.
- **MySQLi procedural** functions — `mysqli_connect`, `mysqli_prepare`,
  `mysqli_stmt_bind_param`. **Not** the `mysqli::` object API. **Not** PDO.
- **Vanilla JS only.** No frameworks, no build step, no Bootstrap/Tailwind.
- **No external APIs at all** — no payment gateway, maps, OAuth, SMS, email, cloud storage,
  WebSockets. Google Fonts via a `<link>` tag is the single exception.
- **No `.sql` files.** The schema lives in `setup.php` as PHP-embedded SQL strings.
- Anything "real-time" is **AJAX polling** (`setInterval` + `fetch` → a PHP endpoint).

If a task seems to need one of these, say so rather than quietly breaking the constraint.

## Page skeleton

```php
<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../config.php';              // only if you need the constants
require_role('admin' | 'seller' | 'customer' | 'rider');
```

Root-level files use `'includes/...'` without the `../`. Pages carry their own full HTML
boilerplate and link their own CSS.

## Validation style — one error variable per field

```php
$nameErr = $emailErr = "";
$name = $email = "";

function cleanInput($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (empty($_POST["name"])) {
        $nameErr = "Name is required";
    } else {
        $name = cleanInput($_POST["name"]);
    }
    // ... one block per field ...

    if (!$nameErr && !$emailErr) {
        // proceed with the DB write
    }
}
```

Errors render inline: `<span class="error"><?php echo $nameErr; ?></span>`
Form values stay sticky: `value="<?php echo htmlspecialchars($name); ?>"`

## Non-negotiable rules

1. **Never run a password through `cleanInput()`** — `htmlspecialchars` corrupts special
   characters. Comment this where it appears.
2. **Every query uses a prepared statement.** Never concatenate user input into SQL. Where a
   query is built dynamically (search filters), build the *SQL shape* from a whitelist and still
   bind the values — see the sort-column whitelist in `admin/sales_overview.php`.
3. **All echoed user data goes through `htmlspecialchars()`.**
4. **Multi-table writes use transactions** — `mysqli_begin_transaction` / `mysqli_commit` /
   `mysqli_rollback` — so they succeed or fail together.
5. Currency renders as `৳<?php echo number_format($amount, 2); ?>`
6. Optional numeric search params: `$id_match = ctype_digit($keyword) ? (int) $keyword : 0;`

## The bug class that has actually bitten this project

`mysqli_stmt_bind_param`'s type string must have **exactly one character per bound value**. A
mismatch is an `ArgumentCountError` fatal on PHP 8 — a 500 page, and nothing catches it until
that query runs. The original checkout bug was `'iisiddd'` (7 chars) for 8 values.

**After adding or editing any query, run:**

```
python3 tests/bindcheck.py
```

## Concurrency: guarded UPDATE + affected_rows

Anything that must happen only once uses a `WHERE` clause that pins the expected state, then
checks `mysqli_stmt_affected_rows()`. This is how the project prevents double-claims,
double-payments and replayed submissions — not by checking first and writing after.

```php
// Only one rider can win this, no matter how many submit at once
$stmt = mysqli_prepare($conn,
    "UPDATE orders SET rider_id = ?
     WHERE order_id = ? AND rider_id IS NULL AND status IN ('confirmed','preparing')");
mysqli_stmt_bind_param($stmt, 'ii', $rider_id, $order_id);
mysqli_stmt_execute($stmt);
if (mysqli_stmt_affected_rows($stmt) === 0) {
    throw new Exception('Already claimed');
}
```

The same pattern guards order status transitions (`AND status = ?`), spending a bid
(`AND converted_order_id IS NULL`), and completing a delivery.

## Shared logic — do not duplicate these

| Concern | Lives in |
| ------- | -------- |
| Who may move an order to which status | `includes/order_status.php` (`can_transition()`) |
| Restoring stock on cancellation | `includes/order_status.php` (`restore_order_stock()`) |
| Who may read/post in an order's chat | `includes/order_chat.php` (`can_access_chat()`) |
| Login / logout / remember-me | `includes/auth.php` (`login_user()`, `logout_user()`) |
| Notifications page for all 4 roles | `includes/notifications_page.php` |
| Order chat page for all 3 participants | `includes/chat_page.php` |

**Never write `$_SESSION['user_id'] = ...` directly.** Call `login_user()` — it also regenerates
the session id, which is what prevents session fixation.

The shared renderers are included by thin role wrappers that set `$role_css` first. Their
relative links resolve from the *including* file's directory, not `includes/`.

## Schema changes

The schema is in `setup.php` and must stay re-runnable.

- **A new table** — just add it to `$tables`. `CREATE TABLE IF NOT EXISTS` covers fresh and
  existing installs alike.
- **A new column** — add it to the `CREATE TABLE` *and* add an entry to the `$migrations` array.
  MySQL has no portable `ADD COLUMN IF NOT EXISTS`, so migrations check `information_schema`
  first.

Anyone with an existing database must re-run `setup.php` afterwards.

## Styling

**Do not write CSS.** Every `assets/css/*.css` is intentionally empty; the styling pass is
deliberately last. Keep using the existing class names in markup — `error`, `success`, `notice`,
`alert`, `critical`, `stat-card`, `chat-mine`, `chat-theirs` — so the later pass has hooks.

## Before saying a change works

Run the checks in the `simplemarket-test` skill. "It lints" is not "it works" — this project has
a full runtime suite, and the pages have real POST handlers that the suites drive over HTTP.
