# OMG Butuan Inventory System: Review and Changes

## A. What was fixed in this version

| # | Problem | Fix | File(s) |
|---|---------|-----|---------|
| 1 | Login built its SQL from raw input (SQL injection, e.g. `' OR 1=1 -- `) | Prepared statement | controller/login.php |
| 2 | Passwords stored with `md5()` | bcrypt (`password_hash`). Old md5 accounts still log in and are upgraded automatically | controller/auth_helpers.php, login.php, register.php |
| 3 | Logout link ran the login script and never ended the session | New `controller/logout.php` (clears session + cookie); sidebar points to it | controller/logout.php, partials/sidebar.php |
| 4 | `register.php` printed the typed password on screen (`print_r`, `echo`) and used raw SQL | Removed; prepared statement; validation (length, unique username, position whitelist); DB errors no longer shown | controller/register.php |
| 5 | `delete.php`: no login check, deleted by plain link (GET), raw SQL, could delete anyone | Login required, POST only, CSRF token, Admin only, cannot delete yourself, prepared statement | controller/delete.php, users.php |
| 6 | `update.php` raw SQL, any position text accepted | Prepared statement, validation, duplicate-username check, CSRF token | controller/update.php, profile.php |
| 7 | `controller/stock.php` and `transaction.php` require `stock_helpers.php`, but the file was named `stock_helpers` (no extension): Stock In/Out and Transaction would crash | Renamed to `stock_helpers.php` | controller/ |
| 8 | Username/error text printed unescaped (XSS) in dashboard, profile, users, login, registration | `htmlspecialchars` | several pages |
| 9 | Password hash was kept in `$_SESSION` and loaded by `users.php` | Removed from session; users page selects only needed columns | login.php, users.php |
| 10 | Browser Back button could show private pages after logout | `no-store` cache headers; session id regenerated on login | controller/authenticator.php, login.php |

**Do once:** run `database/upgrade_users_password.sql` in phpMyAdmin. Until you do, new accounts keep using md5 because the `password` column may be too short for bcrypt. Nothing breaks either way.

**Not tested:** PHP was not available where these changes were made, so the code was reviewed by hand but not run. Test the checklist in section C.

## A2. Stock pages merged into one (new)

**What was wrong:** `stockin.php` and `stockout.php` never talked to the database. Records were added to the HTML table only, so they vanished on refresh, and product/supplier were free text. `transaction.php` had hardcoded "Supplier 1/2/3" options and a reference-number field the server ignored. The real, safe backend (`controller/stock.php` + `stock_helpers.php`) existed but no page used it. About 4,100 lines across three pages did the same job.

**What changed:**

| Before | After |
|--------|-------|
| `stockin.php`, `stockout.php`, `transaction.php`, `controller/transaction.php` (deleted) | **`stock.php`**: one page with Stock In / Stock Out tabs |
| 3 sidebar items (Stock In, Stock Out, Transaction) | 1 item: **Stock In / Out** |
| Data lost on refresh | Saved in `stock_in` / `stock_out`; product stock updates automatically |
| Free-text product and supplier | Product and supplier chosen from the database |
| One product per entry | Several products per entry, one shared reference number (SI-2026-0001 / SO-2026-0001) |
| No history from the database | History table (latest 500) with search, edit and delete; stock is adjusted back on delete/edit |

`stock.php` also shows this month's entries, units and total cost/value. Stock Out checks the available stock and requires a reason (Used, Damaged, Expired, Adjustment, Other). `controller/stock.php` gained a `batch` action and now checks a CSRF token.

**Not tested:** like the other changes, this could not be run here (no PHP). The page template and JavaScript passed static checks only. Test: add a Stock In with 2 products, confirm the product quantities on the Products page went up, then delete the record and confirm they went back down.

## A3. Product units and categories (new)

**Why Stock Out "detected" the unit:** products had no unit at all. Stock In / Out guessed it from the last stock record of that product (and used `pcs` when there was none). Products added from the Products page also saved their first Stock In without a unit, so it became `pcs`.

**What changed:**
- `products` has a new `unit` column. The Products page has a **Unit** dropdown in the Add / Edit window: pack, kilo, can, galon, carton, box, case, pieces/pcs, bottle, sachet. The unit is also shown next to the quantity in the product list and in the CSV export.
- **Stock In / Stock Out always use the unit of the product.** It is shown (not typed) and the server ignores any other value. The old guessing code was removed.
- **Categories** are now Kitchen, Bar Counter, Necessities, Packaging (SKU codes KIT-0001, BAR-0001, NEC-0001, PKG-0001). Old products keep their old category until you edit them.
- The `unit` column is added automatically the first time the Products or Stock page opens. If your MySQL user cannot change tables, run `database/add_product_unit.sql` once in phpMyAdmin.
- Old products get the unit of their last stock record when it matches the new list (kg becomes kilo), otherwise `pcs`. Edit the product to set the right one.
- The units list lives in `controller/unit_helpers.php`; the categories list in `controller/product_helpers.php`.

## A4. Clean start, optional supplier, kilo decimals, more fixes (latest)

**Clean database for the real shop**
- `database/abuzodb_clean.sql`: full structure with NO shop data (no products, suppliers, sales, refunds, stock records, expenses). Import it in phpMyAdmin; it drops and re-creates the tables. The existing user accounts are kept so you can still log in.
- `database/clear_all_data.sql`: same result on a database that is already running (empties the shop tables, keeps users).

**Stock In saves without a supplier.** Supplier is now optional on the Stock In form, the edit window and the server (`controller/stock_helpers.php`). History shows "No supplier".

**Kilo quantities.** A product whose unit is **kilo** can now have decimal stock (2.5, 0.250; up to 3 decimals) everywhere: Products (stock and reorder level), Stock In, Stock Out, edit, CSV export, dashboard and reports. Every other unit stays a whole number, and the server refuses decimals for them. Columns `products.stock_qty`, `products.reorder_level`, `stock_in.quantity`, `stock_out.quantity` became DECIMAL(12,3) (done automatically on first use, or run `database/make_quantities_decimal.sql`). Sales at the counter are still whole numbers.

**Other fixes**
| Problem | Fix |
|---|---|
| Anyone could open registration.php and create an **Admin** account | Only a logged-in Admin can create accounts. The only exception is an empty system (first account becomes Admin). "Sign Up" link removed from the login page |
| No CSRF protection on products, suppliers, expenses, sales/refund and register | Token added to those forms / requests |
| Reports "Stock movement" ignored manual Stock Out records | They are included now (with reference and reason) |
| Editing a product's quantity on the Products page left no history when lowered | Lowering creates a Stock Out (Adjustment), raising a Stock In; both with reference numbers |
| Product edit could overwrite a concurrent stock change | Stock is read under a row lock inside the transaction |
| Sales stock check cut decimals (`(int)`) | Compared as a decimal number |
| The last Admin could demote himself and lock everybody out | Refused |
| Stock entries count ignored records without a reference number | Fixed |
| Empty file `controller/dashboard.php` | Deleted |
| One user had position `admin` (lowercase) | Normalised to `Admin` in the clean SQL |

**Not tested:** PHP/MySQL were not available here, so these changes were checked by reading the code, bracket-balance checks on every changed PHP file and a JavaScript syntax check only. Run the checklist below.

**Extra test items:** Stock In for a **kilo** product with 2.5 works and the Products page shows 2.5; the same on a **pcs** product is refused ("whole number"); Stock In with no supplier saves; `registration.php` opened while logged out redirects to the login page.

## B. Recommended improvements (not changed yet)

### Security
1. **Roles** are enforced (see RBAC_GUIDE.md) and registration is Admin-only (A4).
2. **CSRF tokens** now cover every form and JSON endpoint except login.
3. **Login throttling.** Nothing stops unlimited password guessing. Add a failed-attempt counter and a short lockout.
4. **Database login** is `root` with an empty password in `database/connect.php`. Create a dedicated MySQL user with only the needed privileges and keep credentials out of the repository.
5. **Messages in the URL** (`?success=...`, `?error=...`) can be edited by anyone to show fake text. Escaped, so not dangerous, but session flash messages (as used in login/users) are cleaner.
6. **Session cookie settings** (`httponly`, `samesite`, `secure` on HTTPS) and a session timeout.
7. **Profile update** lets a user change their own position to Admin. Once roles are enforced, only Admins should change positions. There is also no "change password" feature.

### Data and logic
8. **No `.sql` file** is included. Add a full schema + sample data export (tables seen in code: users, products, suppliers, sales, sale_items, stock_in, stock_out, refunds, expenses). Without it nobody can run the project.
9. **Foreign keys and indexes** (sale_items, stock_in/out, refunds) and a UNIQUE key on `users.username` and `products.sku`.
10. **Refunds do not return stock** by design (made-to-order drinks), but ingredients are not tracked per drink, so selling a drink does not reduce ingredient stock. A simple recipe table would fix this.
11. **Users cannot be edited by an Admin** (reset password, change role). Only self-edit exists.
12. **Deleting a user** with sales history is blocked by the database (good); consider a "deactivate" flag instead.

### Code quality
13. **Duplicate code:** `h()` / `peso()` / DB-timezone setup is repeated in many pages. Move to one shared include.
14. **Large page files** with inline CSS/JS (`sales.php`, `products.php`, `reports.php`). The stock pages were merged (see A2); the same idea can be applied here.
15. **Font Awesome loads from an online kit**; icons disappear without internet. Bundle it locally for a school demo.
16. **Mixed styles:** older files use `$_POST[...]` directly and CRLF line endings; newer ones use helpers and prepared statements. Align everything to the newer style.
17. **Empty `controller/dashboard.php`** can be deleted.
18. **No README / setup guide** (XAMPP steps, DB import, default login). Needed for the panel defense.

## C. Test checklist
- [ ] Login with an existing (old md5) account works
- [ ] Login with `' OR 1=1 -- ` as username is rejected
- [ ] Logout returns to the login page; Back button does not show the dashboard
- [ ] Register a new account, then log in with it
- [ ] Users page: Admin can delete another user; cannot delete self; Staff is refused
- [ ] Profile update works
- [ ] Stock In / Out page: add a Stock In with 2 products; Products page quantities go up
- [ ] Stock Out with a reason lowers the quantities; trying to remove more than available is refused
- [ ] Edit and delete a stock record; product quantities adjust back
- [ ] Records are still there after refreshing the page


## Inventory-only change (Sales removed, Cost Price per unit)

The system now only manages the INVENTORY (what is bought and kept in stock), not the drinks/products that are sold.

- **Sales removed**: `sales.php`, `controller/sale.php` and `controller/get_sales_chart.php` are deleted. The Sales link, the `sales.process` / `sales.refund` permissions, and every sales / profit / refund / revenue figure on the Dashboard and Reports are gone.
- **Dashboard**: cards are now Total Products, Low Stock, Stock In, Stock Out, Total Items, Inventory Value, Out of Stock, Suppliers. The chart is "Stock Movement by Date" (Stock In vs Stock Out) from `controller/get_stock_chart.php`.
- **Reports**: Business Performance became "Inventory Summary" (stock in/out units and value at cost, expenses). The Sales Report is removed; the Stock Movement and Person-in-Charge reports use stock in / stock out only.
- **Selling price removed**: no longer in the Add/Edit form, the table, the CSV exports, the controller, or the database.
- **Price -> Cost Price**: `products.cost_price` is the cost of ONE unit (per kilo, per pack, per sachet...). The Products table column is now "Cost Price" with the unit (e.g. `₱45.00 / kilo`). Inventory Value = quantity x cost price. Stock In / Stock Out records keep using it as `unit_cost`.
- **Unit tag**: Stock Quantity, Reorder Level and Cost Price inputs in the Add/Edit form show the chosen unit next to the number and change when the unit changes.
- **Database**: run `database/remove_sales_and_selling_price.sql` once on your existing database (drops `sales`, `sale_items`, `refunds` and `products.selling_price`). `abuzodb_clean.sql` and `clear_all_data.sql` are already updated.

## Profile page removed
`profile.php` and `controller/update.php` (used only by the Profile page) are deleted, together with the `profile.edit` permission and the Profile sidebar link. A user's position (Admin / Staff) is now set only when an Admin creates the account (Users page -> Add a new user). Users can no longer edit their own username or position; an Admin can delete and re-create an account if a change is needed.
