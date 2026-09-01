# SimpleMarket — Project Handoff

> Read this file fully before writing any code. It contains the stack constraints, code conventions, database schema, feature spec, and exactly what is built vs. unbuilt.

---

## 1. What this is

**SimpleMarket** — a mini multi-vendor local marketplace for Dhaka's small-business ecosystem (home-based food sellers, university merch, local boutiques), with an in-house delivery rider network.

Four user roles: **Admin**, **Seller (Vendor)**, **Customer (Buyer)**, **Delivery Person (Rider)**.

Local project path: `htdocs/SimpleMarket/` (note the capital S and M — paths are case-sensitive).
Repo: `github.com/Safayet-Sopan/SimpleMarket`

---

## 2. Stack constraints — STRICT, do not deviate

- **Frontend:** HTML5, CSS3, Vanilla JS only. No frameworks, no build tools, no Bootstrap/Tailwind.
- **Backend:** PHP, **procedural style only** — no classes, no OOP, no Composer, no PSR autoloading.
- **DB access:** MySQLi **procedural** functions (`mysqli_connect`, `mysqli_prepare`, `mysqli_stmt_bind_param`) — NOT the OOP `mysqli::` API, NOT PDO.
- **Database:** MySQL via XAMPP.
- **No external APIs of any kind:** no payment gateways, no maps, no OAuth, no SMS/email, no cloud storage, no WebSockets. Google Fonts (`<link>` tag only) is the single allowed exception.
- **No `.sql` files.** The schema lives in `setup.php` as PHP-embedded SQL strings.
- Any "real-time" feature (chat, notifications) = **AJAX polling** (`setInterval` + `fetch` → PHP endpoint), never sockets.

---

## 3. Code conventions already established — follow these exactly

### Every protected page starts with:

```php
<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_role('admin' | 'seller' | 'customer' | 'rider');
```

(Root-level files use `'includes/...'` without the `../`.)

### Validation style — one error variable per field

This project uses a specific validation pattern. Match it:

```php
$nameErr = $emailErr = $passwordErr = "";
$name = $email = "";

function cleanInput($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (empty($_POST["name"])) {
        $nameErr = "Name is required";
    } else {
        $name = cleanInput($_POST["name"]);
        if (!preg_match("/^[a-zA-Z-' ]*$/", $name)) {
            $nameErr = "Only letters and white spaces are allowed.";
        }
    }
    // ... one block per field ...

    if (!$nameErr && !$emailErr && !$passwordErr) {
        // proceed with DB write
    }
}
```

Errors render inline next to each field: `<span class="error"><?php echo $nameErr; ?></span>`
Form values are sticky: `value="<?php echo htmlspecialchars($name); ?>"`

### Non-negotiable rules

- **Passwords are NEVER run through `cleanInput()`** — `htmlspecialchars` would corrupt special characters. Comment this where relevant.
- **All DB queries use prepared statements** (`mysqli_prepare` + `mysqli_stmt_bind_param`). Never string-concatenate user input into SQL.
- **All echoed user data uses `htmlspecialchars()`**.
- **Multi-table writes use transactions** (`mysqli_begin_transaction` / `mysqli_commit` / `mysqli_rollback`) so they succeed or fail together.
- Currency displays as `৳<?php echo number_format($amount, 2); ?>`
- For optional numeric search params: `$id_match = ctype_digit($keyword) ? (int) $keyword : 0;` (falls back to 0, matches nothing, avoids bind type errors)

### Styling

**Ignore CSS entirely for now.** All `assets/css/*.css` files exist but are empty. Pages are plain unstyled HTML tables/forms by design. Do not write CSS. A styling pass happens later (planned: Swiss-institutional aesthetic, terracotta `#C15B3C`, sage `#5C7A5E`, slate blue `#4A6377`, Inter/Inter Tight). Class names like `error`, `success`, `notice`, `alert`, `critical`, `stat-card` are already used in markup — keep using them, just don't style them.

---

## 4. Database schema (14 tables)

Defined in `setup.php`. Run `http://localhost/SimpleMarket/setup.php` once to create everything (idempotent, uses `IF NOT EXISTS`).

| Table             | Key columns                                                                                                                                                                                                                                                 |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `users`           | user_id, full_name, email (unique), phone, password_hash, role ENUM(admin/seller/customer/rider), status ENUM(active/pending/suspended), profile_image, created_at                                                                                          |
| `categories`      | category_id, category_name                                                                                                                                                                                                                                  |
| `seller_profiles` | seller_id, user_id FK, shop_name, shop_address, business_type, commission_rate DECIMAL(5,2) DEFAULT 10.00, **payment_methods VARCHAR(255) DEFAULT 'cod'**, approval_status ENUM(pending/approved/rejected), applied_at                                    |
| `rider_profiles`  | rider_id, user_id FK, vehicle_type, vehicle_plate, vehicle_capacity, availability_status ENUM(available/busy/offline)                                                                                                                                       |
| `products`        | product_id, seller_id FK, category_id FK, product_name, description, price, stock_quantity, low_stock_threshold DEFAULT 5, product_image, status ENUM(active/inactive), created_at                                                                          |
| `orders`          | order_id, customer_id FK, seller_id FK, rider_id FK (nullable), delivery_address, fast_delivery TINYINT, delivery_fee, subtotal, commission_amount, total_amount, **payment_method VARCHAR(20)**, **payment_status ENUM(unpaid/paid)**, status ENUM(pending/confirmed/preparing/out_for_delivery/delivered/cancelled), created_at |
| `order_items`     | order_item_id, order_id FK, product_id FK, quantity, unit_price, line_total                                                                                                                                                                                 |
| `offers`          | offer_id, product_id FK, customer_id FK, offered_price, counter_price, status ENUM(pending/accepted/countered/rejected), **converted_order_id INT NULL**, created_at                                                                                        |
| `reviews`         | review_id, product_id FK, customer_id FK, order_id FK, rating TINYINT, comment, created_at                                                                                                                                                                  |
| `seller_ratings`  | rating_id, seller_id FK, customer_id FK, order_id FK, rating TINYINT, comment, created_at                                                                                                                                                                   |
| `messages`        | message_id, order_id FK, sender_id FK, message_text, is_read, sent_at                                                                                                                                                                                       |
| `earnings`        | earning_id, rider_id FK, order_id FK, amount, earned_at                                                                                                                                                                                                     |
| `notifications`   | notification_id, user_id FK, message, is_read, created_at                                                                                                                                                                                                   |
| `remember_tokens` | token_id, user_id FK, token_hash CHAR(64) UNIQUE, expires_at, created_at                                                                                                                                                                                    |

**Schema design decisions already made:**

- `users.status` = account-level state; `seller_profiles.approval_status` = the admin approval queue. Both are updated together when an admin approves/rejects.
- `orders.commission_amount` and `delivery_fee` are **computed and stored at checkout**, not recalculated on read.
- `offers` links to `products`, not `orders` — a bid happens before an order exists.
- `reviews` (product-level) and `seller_ratings` (seller-level) are deliberately separate tables — they are two distinct unique features.
- `messages.order_id` makes chat strictly order-scoped.
- One order = one product = one seller. There is **no multi-seller cart**. Don't add one without asking.
- `offers.converted_order_id` records which order an accepted bid became. It is what stops one
  accepted bid being redeemed for unlimited discounted orders.
- `orders.payment_method` / `payment_status` are manual bookkeeping. There is no gateway — the
  seller confirms the money arrived from their Orders page.
- `remember_tokens` stores only a **SHA-256 hash** of the login token. The raw token lives solely
  in the browser cookie, so a dumped database cannot be replayed as a login.
- Order status is owned by different roles at different points: the **seller** drives
  pending → confirmed → preparing, the **rider** drives preparing → out_for_delivery → delivered,
  and the **customer** may only cancel while still pending. The rules live in one place,
  `includes/order_status.php`, so the forms and the AJAX endpoint cannot drift apart.

---

## 5. Full feature spec

### Common features (all 4 roles)

Login / Register / Logout · View & edit profile · Change password · Dashboard home · Notifications · Search · Order status tracking

### Unique features (logic/computed, not plain CRUD)

**All 12 unique features are now built** — 3 per role.

| Admin                          | Seller                              | Customer                             | Rider                               |
| ------------------------------ | ----------------------------------- | ------------------------------------ | ----------------------------------- |
| ✅ Auto commission calculator  | ✅ Low stock alert                  | ✅ Fast delivery option              | ✅ Update vehicle                   |
| ✅ Application approval sort   | ✅ Price bidding with customer      | ✅ Seller rating                     | ✅ Chatbox (polling, order-linked)  |
| ✅ Sales overview of sellers   | ✅ Set payment method for customer  | ✅ Product feedback after receiving  | ✅ Earnings calculator              |

Notes on where a few of them actually live:

- **Update vehicle** is inside `rider/profile.php`, not a file of its own.
- **Price bidding** spans `seller/price_bidding.php` and `customer/make_offer.php` — a bid needs
  both sides. An accepted bid carries its price into checkout and is then marked spent.
- **Chatbox** is one shared renderer (`includes/chat_page.php`) surfaced as `rider/chatbox.php`,
  `customer/chat.php` and `seller/chat.php`, backed by two AJAX endpoints.
- **Set payment method** was unscoped before. It is now: the seller ticks which methods the shop
  accepts, the customer picks one of those at checkout, and the seller confirms payment by hand
  from the Orders page. No gateway, per the stack rules.

---

## 6. File structure

```
SimpleMarket/
├── setup.php                 ✅ DB + 13 tables + migrations + category seed (re-runnable)
├── create_admin.php          ✅ guarded — closed once an admin exists
├── config.php                ✅ SITE_NAME, BASE_URL, UPLOAD_PATH, delivery fees,
│                                RIDER_EARNING_RATE, $PAYMENT_METHODS
├── index.php                 ✅ redirects logged-in users to their role dashboard
├── login.php                 ✅
├── register.php              ✅
├── logout.php                ✅
├── includes/
│   ├── db.php                ✅ mysqli_connect → $conn
│   ├── auth.php              ✅ require_login(), require_role(), current_user_id(), current_role()
│   ├── functions.php         ✅ sanitize(), redirect(), flash_set(), flash_get(), is_logged_in()
│   ├── order_status.php      ✅ NEW — who may move an order where; restore_order_stock(); notify_user()
│   ├── order_chat.php        ✅ NEW — chat_participants(), can_access_chat()
│   ├── notifications_page.php ✅ NEW — shared notifications renderer for all 4 roles
│   ├── chat_page.php         ✅ NEW — shared order-chat renderer
│   ├── header.php            ⬜ empty stub
│   ├── footer.php            ⬜ empty stub
│   └── navbar.php            ⬜ empty stub
├── admin/
│   ├── dashboard.php         ✅  ├── profile.php ✅  ├── change_password.php ✅  ├── search.php ✅
│   ├── seller_approvals.php  ✅ UNIQUE — sortable queue, approve/reject + notification
│   ├── notifications.php     ✅
│   ├── commission_calculator.php ✅ UNIQUE — per-seller commission, editable rates, live estimator
│   └── sales_overview.php    ✅ UNIQUE — sortable, date-filterable seller sales table
├── seller/
│   ├── dashboard.php         ✅  ├── profile.php ✅  ├── change_password.php ✅  ├── search.php ✅
│   ├── products.php          ✅ full CRUD + image upload + deactivate toggle
│   ├── low_stock_alert.php   ✅ UNIQUE
│   ├── price_bidding.php     ✅ UNIQUE — accept / reject / counter customer bids
│   ├── payment_methods.php   ✅ UNIQUE — which methods the shop accepts
│   ├── orders.php            ✅ confirm / prepare / cancel + mark paid
│   ├── chat.php              ✅ order chat (shared renderer)
│   └── notifications.php     ✅
├── customer/
│   ├── dashboard.php         ✅  ├── profile.php ✅  ├── change_password.php ✅  ├── search.php ✅
│   ├── fast_delivery.php     ✅ UNIQUE — checkout; honours an accepted bid price
│   ├── seller_rating.php     ✅ UNIQUE — rate the shop, delivered orders only
│   ├── product_feedback.php  ✅ UNIQUE — review the product, delivered orders only
│   ├── make_offer.php        ✅ UNIQUE — customer side of price bidding
│   ├── orders.php            ✅ list + cancel while pending
│   ├── order_tracking.php    ✅ status timeline + order list
│   ├── chat.php              ✅ order chat (shared renderer)
│   └── notifications.php     ✅
├── rider/
│   ├── dashboard.php         ✅  ├── profile.php ✅ (UNIQUE: vehicle fields)  ├── change_password.php ✅
│   ├── search.php            ✅
│   ├── deliveries.php        ✅ claim available orders, advance status, complete
│   ├── chatbox.php           ✅ UNIQUE — order chat (shared renderer)
│   ├── earnings_calculator.php ✅ UNIQUE — totals, averages, best day, date filter
│   └── notifications.php     ✅
├── ajax/
│   ├── poll_notifications.php ✅ unread count + newest message
│   ├── poll_messages.php     ✅ order-scoped chat fetch, marks the other side read
│   ├── send_message.php      ✅ posts one message
│   └── update_order_status.php ✅ JSON status change, same rules as the forms
├── assets/
│   ├── css/  style.css, admin.css, seller.css, customer.css, rider.css  ⬜ all empty — LEAVE EMPTY
│   └── js/
│       ├── validation.js     ✅ role-conditional field toggle on register form
│       ├── main.js           ✅ polls unread notification count
│       └── chat_poll.js      ✅ chat polling + send
├── .claude/
│   └── skills/               ✅ NEW — simplemarket-conventions / -page / -test
├── tests/                    ✅ NEW — see tests/README.md
│   ├── e2e_test.php          logic suite against a throwaway DB (66 checks)
│   ├── http_smoke.sh         loads every page as all 4 roles (48 checks)
│   ├── flow_order_lifecycle.sh  drives the real page POSTs end to end
│   ├── flow_bidding.sh       drives the real bidding POSTs end to end
│   ├── flow_session.sh       sessions + remember-me cookie (18 checks)
│   ├── seed_demo.php         demo accounts + sample data
│   └── bindcheck.py / schemacheck.py / linkcheck.py   static checks
└── uploads/
    ├── products/             (needs chmod 777 locally; not tracked by git)
    └── profiles/
```

---

## 7. What is built

Every page in §6 marked ✅ exists and is syntactically valid. The complete flows are:

**Onboarding** — register as customer/seller/rider → seller lands in `pending` and is blocked at
login → admin approves in `seller_approvals.php` → `users.status` and `approval_status` flip
together and a notification is written → seller can log in.

**Buying** — customer searches → "Order Now" → checkout picks a delivery speed and one of the
payment methods that shop accepts → order + order_items written in a transaction, stock
decremented under a guard that refuses to oversell → redirect to a live tracking timeline.

**Bidding** — customer bids below the listed price → seller accepts, rejects or counters →
customer accepts the counter → "Order at ৳X" carries the agreed price into checkout → the bid is
stamped with `converted_order_id` inside the same transaction, so it cannot be spent twice.

**Fulfilment** — seller confirms → preparing → any rider claims it from the available list (an
atomic guarded UPDATE, so two riders cannot take the same order) → out_for_delivery → delivered,
which writes the rider's earnings row and frees them up.

**After delivery** — the customer can review the product and separately rate the shop. Both are
gated on `status = 'delivered'` and both refuse a second submission for the same order.

**Throughout** — notifications are written at every hop and polled live; order-scoped chat is open
to exactly the customer, the shop and the assigned rider.

---

## 8. Verification status — everything below was actually run

**Static checks — all passing.** Re-run after any change:

| Check | Result |
| ----- | ------ |
| `php -l` on every file | 60 files, clean |
| `node --check` on every JS file | 3 files, clean |
| `bind_param` arity (placeholders vs type chars vs args) | 151 call sites, no mismatches |
| Every `alias.column` in every query vs the real schema | 0 mismatches |
| Internal links and `require` paths | 323 refs, 0 broken |

The arity check exists because the original checkout bug was exactly that class — a 7-character
type string for 8 bound values, a silent `ArgumentCountError` fatal on PHP 8. **Re-run it after
adding queries.**

**Runtime tests — all passing against a live XAMPP.**

| Suite | Result |
| ----- | ------ |
| `tests/e2e_test.php` (throwaway DB) | **66 / 66** |
| `tests/http_smoke.sh` (every page over HTTP, all 4 roles) | **48 / 48** |
| `tests/flow_order_lifecycle.sh` (real page POSTs) | full lifecycle verified |
| `tests/flow_bidding.sh` (real page POSTs) | full bid flow verified |
| `tests/flow_session.sh` (sessions + remember-me cookie) | **18 / 18** |

What the runtime tests actually proved, end to end through the real pages:

- Checkout writes a correct order — ৳1000 subtotal, ৳70 fast-delivery fee, ৳100 commission at
  10%, ৳1070 total — and decrements stock 20 → 18
- Overselling is refused, and a refused order leaves both stock and the orders table untouched
- A seller cannot jump `preparing → delivered`; the page refuses it and the status holds
- Two riders cannot claim the same order; the loser's UPDATE affects zero rows
- Delivery writes exactly one earnings row at ৳56.00 (80% of the ৳70 fee), frees the rider, and
  **replaying the delivery POST does not pay twice**
- A bid below list is accepted, at-or-above list is refused, a duplicate open bid is refused,
  a counter above list price is refused
- An accepted counter carries ৳450 into checkout (order and line item both), stamps the offer
  with `converted_order_id`, and **replaying that same bid yields a full-price ৳500 order**
- Chat is readable only by the customer, the shop and the assigned rider — anyone else gets
  `{"error":"Not your order"}`
- Reviews and shop ratings are gated on `delivered` and refuse a second submission
- Every role is redirected to login when reaching for another role's pages

Two bugs were found and fixed during testing, both in the **harnesses**, not the app: a wrong
assertion that checked notification rows without exercising the code that writes them, and a zsh
`path` variable that shadowed `$PATH`.

**State of your database:** `setup.php` has been run against `simplemarket_db` — all four
columns were added and 5 categories seeded. `tests/seed_demo.php` created four demo accounts
(password `Passw0rd!`) plus sample orders, and the lifecycle tests left a few more orders behind.
That data is handy for demoing; delete the `*@demo.local` users to clear it out.

---

## 8a. Sessions and cookies

All of this lives in `includes/auth.php`. Pages do not touch `$_SESSION` directly any more —
they call `login_user()` / `logout_user()`.

**Two cookies, different jobs:**

| Cookie | Lifetime | Purpose |
| ------ | -------- | ------- |
| `PHPSESSID` | until the browser closes | the actual logged-in session |
| `simplemarket_remember` | 30 days, opt-in | rebuilds a session after the browser closes |

Both are `HttpOnly` (JavaScript cannot read them) and `SameSite=Lax`. `secure` is deliberately
**false** because local XAMPP is plain HTTP — turn it on in `auth.php` if this is ever served
over HTTPS.

**How "remember me" works:**

1. Ticking the box on login generates a 64-character random token
2. The **raw token** goes into the cookie; only its **SHA-256 hash** goes into `remember_tokens`
3. When a request has no session but does have the cookie, `try_remember_login()` hashes the
   cookie, looks up the row, and rebuilds the session — but only if the token is unexpired *and*
   `users.status = 'active'`, so a suspended account cannot walk back in on an old cookie
4. Logging out deletes that token row and expires the cookie

**Other session hardening added at the same time:**

- `session_regenerate_id(true)` on every login, which is what defeats session fixation
- a 30-minute idle timeout (`SESSION_IDLE_SECONDS`)
- `require_login()` gives the remember cookie a chance before redirecting anyone away

**Deliberate simplification:** the token is *not* rotated on each use. Rotating would mean a
stolen cookie stops working after the real user's next visit, but it complicates concurrent
requests. Given "as simple as possible", one token per login it is — worth revisiting if this
ever leaves localhost.

Covered by `tests/flow_session.sh` — **18/18**, including that the raw token is never in the
database, that a forged token is refused, and that logout revokes the cookie for real.

---

## 8b. Claude Code skills

`.claude/skills/` holds three skills so a future session does not have to rediscover this
project's rules. Invoke with `/<name>`.

| Skill | Use it when |
| ----- | ----------- |
| `simplemarket-conventions` | Before writing or editing any PHP here. Stack constraints, the validation pattern, prepared statements, the guarded-UPDATE concurrency pattern, where shared logic lives, schema-change rules. |
| `simplemarket-page` | Adding a new role page. Skeleton, profile-id lookup, ownership scoping, navigation wiring, what to update afterwards. |
| `simplemarket-test` | After any change. How to run the static and runtime suites, and how to tell a broken test from broken code. |

They deliberately record the mistakes this project has already made — the `bind_param` arity
fatal, stale `progress.md` entries, and the two zsh traps in the shell suites — so they are not
repeated.

---

## 9. Known follow-ups

- [ ] `includes/header.php`, `footer.php`, `navbar.php` are still empty — pages repeat their own
      HTML boilerplate. Now that `notifications_page.php` and `chat_page.php` prove the shared
      include pattern works, consolidating these is low risk
- [ ] `assets/css/*.css` are all still empty, by design — the styling pass is deliberately last
- [ ] Product images upload to `uploads/products/`, but nothing displays them yet on the customer
      search results
- [ ] `ajax/update_order_status.php` is written and enforces the same rules as the forms, but no
      page calls it yet — the status buttons are ordinary form POSTs, which work without JS. It is
      there for when a page wants no-reload updates
- [ ] Chat has no per-order unread badge on the dashboards, only inside the chat page itself
- [ ] Riders claim orders from an open pool with no distance or capacity check — there is no maps
      API, so any rider can take any order

---

## 10. Build order for what's left

All 12 unique features are built and the test suites pass, so what remains is polish:

1. Display product images on the customer search and checkout pages — they upload fine but are
   never shown
2. Consolidate `header.php` / `footer.php` / `navbar.php`
3. Styling pass — **last**, after everything above. Swiss-institutional aesthetic, terracotta
   `#C15B3C`, sage `#5C7A5E`, slate blue `#4A6377`, Inter/Inter Tight. Class names
   (`error`, `success`, `notice`, `alert`, `critical`, `stat-card`, `chat-mine`, `chat-theirs`)
   are already in the markup waiting for it

---

## 11. Local setup (for a fresh machine)

1. Clone into `htdocs/SimpleMarket/`
2. Start Apache + MySQL in XAMPP
3. Visit `http://localhost/SimpleMarket/setup.php` once → creates DB + all tables
4. Visit `http://localhost/SimpleMarket/create_admin.php` once → creates admin login
5. `mkdir -p uploads/products uploads/profiles && chmod -R 777 uploads` (git doesn't track empty dirs; uploads fail without this)
6. Go to `http://localhost/SimpleMarket/login.php`

**Already have the project set up? Re-run `setup.php` once.** It is idempotent and will not
touch existing data, but this pass added four columns and the category seed, and the app will
throw "Unknown column" errors until it runs:

- `offers.converted_order_id`
- `seller_profiles.payment_methods`
- `orders.payment_method`
- `orders.payment_status`
- the whole `remember_tokens` table (a new table, so plain `CREATE TABLE IF NOT EXISTS` covers
  both fresh and existing installs — no migration entry needed)

`setup.php` now has a migrations block that checks `information_schema` before each `ALTER`,
because MySQL has no portable `ADD COLUMN IF NOT EXISTS`. Fresh installs get the same columns
straight from the `CREATE TABLE` statements, so both paths converge.

### Order status, at a glance

```
                  seller                    seller          rider            rider
   pending  ──────────────▶  confirmed  ───────────▶  preparing  ────▶  out_for_delivery  ────▶  delivered
      │                          │                        │                                          │
      │ customer or seller       │ seller                 │ seller                          writes earnings,
      ▼                          ▼                        ▼                                 frees the rider,
  cancelled  ◀──────────────────────────────────────────────                                unlocks review
      │                                                                                        + rating
      └── restores stock in the same transaction
```

A rider claims an order while it is `confirmed` or `preparing`. The rules live in
`includes/order_status.php`.
