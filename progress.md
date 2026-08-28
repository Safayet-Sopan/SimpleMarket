# SimpleMarket — Progress Log

**Stack:** HTML5, CSS3, Vanilla JS, PHP (procedural), MySQLi, MySQL, XAMPP. No external APIs (Google Fonts is the one allowed exception). No frameworks, no OOP, no `.sql` files — schema lives in `setup.php`.

---

## ✅ Done

### Project setup

- Full folder/file skeleton created and pushed to GitHub (`Safayet-Sopan/SimpleMarket`)
- `setup.php` — one-time browser script that creates the database + all 13 tables (`IF NOT EXISTS`, safe to re-run). Teammates just open this once after cloning.
- `create_admin.php` — browser form to create an admin account locally (⚠️ not safe for production, no auth guard yet — flagged as a follow-up)
- Database schema finalized: `users`, `categories`, `seller_profiles`, `rider_profiles`, `products`, `orders`, `order_items`, `offers`, `reviews`, `seller_ratings`, `messages`, `earnings`, `notifications`

### Core includes

- `includes/db.php` — mysqli connection
- `includes/auth.php` — `require_login()`, `require_role()`, `current_user_id()`, `current_role()`, session guard
- `includes/functions.php` — `sanitize()`, `redirect()`, flash message helpers

### Auth flow

- `register.php` — full validation (per-field error style), role-conditional fields (shop name for seller, vehicle type for rider) with JS toggle in `assets/js/validation.js`, sellers get `status = 'pending'` on signup
- `login.php` — session creation, blocks suspended/pending accounts with specific messages
- `logout.php`, `index.php`

### Dashboards (all 4 roles)

- `admin/dashboard.php` — pending approvals, active sellers, delivered orders, revenue
- `seller/dashboard.php` — product count, low stock count, pending orders
- `customer/dashboard.php` — active orders, orders awaiting feedback
- `rider/dashboard.php` — active deliveries, total earnings

### Common features

- **Profile view/edit** — all 4 roles done (`profile.php`). Seller updates `seller_profiles` too; rider updates `rider_profiles` too (vehicle fields folded in here — `update_vehicle.php` is now redundant, not yet deleted)
- **Change password** — all 4 roles done, current-password verification + strength check + reuse block
- **Search** — all 4 roles done, role-scoped queries (customer→products, seller→own products/orders, admin→users, rider→own deliveries)

### Seller unique features

- `products.php` — full CRUD, image upload (validated by MIME type, 2MB limit), deactivate/reactivate toggle (no hard delete, protects order history)
- `low_stock_alert.php` — flags products at/below threshold

### Admin unique features

- `seller_approvals.php` — pending queue, sortable by `applied_at`, approve/reject actions update `users.status` + `seller_profiles.approval_status`, sends a notification to the seller

### Customer flow (in progress)

- `fast_delivery.php` — doubles as the checkout/order-placement page (per original spec: "checkout toggle affecting delivery fee"). Handles quantity, stock re-check inside a transaction (prevents overselling), flat delivery fee logic (৳30 standard / ৳70 fast), commission calculation, notifies seller on order. **Not yet linked from `search.php`** — currently tested by typing the URL manually.

---

## 🚧 Known issues / follow-ups

- [ ] Delete or repurpose `rider/update_vehicle.php` (redundant — folded into `rider/profile.php`)
- [ ] Fix `rider/dashboard.php` link that still points to `update_vehicle.php` → should point to `profile.php`
- [ ] Add "Order Now" link from `customer/search.php` → `fast_delivery.php?product_id=...`
- [ ] Currently debugging: "page isn't working" error after order submission — checking PHP error log / redirect loop vs 500 error
- [ ] `create_admin.php` has no safeguard against being run repeatedly by anyone who finds the URL — needs a guard before this goes anywhere near production
- [ ] `categories` table is empty — needs a few seed rows (Food, Merch, Boutique, etc.) for the category dropdown to be meaningful
- [ ] No styling applied yet anywhere — all pages are unstyled HTML tables/forms (by design, styling comes after markup is done)

---

## 🔜 Next steps (in order)

1. **Fix the checkout error** (currently blocking) — diagnose the "page isn't working" issue on `fast_delivery.php` submit
2. **`customer/order_tracking.php`** — status timeline for a specific order; `fast_delivery.php` already redirects here after a successful order
3. **`notifications.php` + `ajax/poll_notifications.php`** — per-role notification list + first AJAX polling feature (live unread badge). Real data already exists (seller approvals, new orders)
4. **`seller/price_bidding.php`** — customer offers, seller accept/counter/reject (`offers` table)
5. **`customer/seller_rating.php`** and **`customer/product_feedback.php`** — both need delivered orders to exist first
6. **Rider-side order handling** — currently no page assigns a rider to an order or lets a rider update order status (`rider/deliveries.php` exists as a stub but is unbuilt)
7. **`rider/chatbox.php` + `ajax/poll_messages.php` / `send_message.php`** — order-linked polling chat
8. **`rider/earnings_calculator.php`** — depends on the `earnings` table having real rows, which depends on completed deliveries existing
9. **`admin/commission_calculator.php`** and **`admin/sales_overview.php`** — both can be built now since `orders.commission_amount` is already being populated at checkout
10. **Styling pass** — apply the Swiss-institutional aesthetic (terracotta `#C15B3C`, sage `#5C7A5E`, slate blue `#4A6377`, Inter/Inter Tight) across `assets/css/*.css` once the remaining pages exist
