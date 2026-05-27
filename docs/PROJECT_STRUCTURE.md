# Mobimend Project Structure

This repository uses `php_backend` as the canonical PHP/MySQL product backend. Legacy PHP entry points have been removed so stock writes cannot drift through a second PHP code path.

## Recommended Ownership

| Path | Purpose | Status |
| --- | --- | --- |
| `php_backend/` | Main PHP/MySQL application: public PHP pages, DB schema, bootstrap, config, helpers. | Primary |
| `php_backend/public/` | Browser-facing PHP entry points such as repair booking, wholesale checkout, and admin screens. | Primary |
| `php_backend/database/schema.sql` | Canonical MySQL schema for the commerce, repair, wholesale, payment, blog, and admin model. | Primary |
| `public/` | Static HTML/CSS/JS prototype and image assets. Good for design migration into PHP pages. | Prototype |
| `public/assets/` | Brand, repair, part, device, staff, and marketing images. | Shared assets |
| `backend/` | Node/Mongo API for auth, inventory service routes, movement audit, and queued low-stock alerts. | Service |
| `app/` | Older MVC-style PHP folders without a complete current entry point. | Legacy/reference |
| `server/` | Empty or unused server workspace. | Archive candidate |
| `storage/` | Logs and future upload storage. | Keep |

## Suggested Next Organization Step

Do not move working files until the PHP backend is stable. Instead, consolidate new work like this:

- Add customer/admin pages under `php_backend/public`.
- Add shared PHP logic under `php_backend/src`.
- Store product media in cloud object storage and save HTTPS URLs in `media_url`.
- Reuse image files from `public/assets` until there is a dedicated asset pipeline.
- Move static HTML prototypes into `docs/prototypes` later, after each page has a PHP equivalent.

## Page Map

Customer pages:

- `index.php`: homepage and service overview.
- `repair.php`: device, issue, appointment slot, and customer details.
- `shop.php`: accessories catalog, search, filters, variants, add to cart.
- `cart.php` and `checkout.php`: retail checkout.
- `wholesale.php`: wholesale catalog with MOQ rules and stock-aware checkout.
- `blog.php` and `post.php`: repair guides, product education, and search.
- `account.php`: profile, saved addresses, order history, and repair tracking.
- `track.php`: public order or repair tracking by reference number.

Admin pages:

- `admin_inventory.php`: stock, product, variant, and low-stock work.
- `admin_orders.php`: order list, status updates, invoice printing.
- `admin_repairs.php`: booking list, technician assignment, repair status.
- `admin_payments.php`: M-Pesa callbacks, bank statement upload review, reconciliation.
- `admin_users.php`: customers, staff, permissions, wholesale approvals.
- `admin_blog.php`: category and post publishing.
- `admin_reports.php`: sales, stock movement, popular products, repair statistics.

## API Shape

PHP can expose JSON endpoints under `php_backend/public/api`. Keep endpoints grouped by domain:

- `api/products.php`
- `api/orders.php`
- `api/bookings.php`
- `api/payments/mpesa_callback.php`
- `api/auth.php`
- `api/inventory.php`
- `api/reports.php`

Python can be added later as a support service for diagnostics, forecasting, recommendations, or data imports without taking over the core PHP checkout and admin flows.
