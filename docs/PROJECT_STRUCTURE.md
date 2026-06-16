# Mobimend Project Structure

This repository uses `php_backend` as the canonical PHP/MySQL product backend. Legacy PHP entry points have been removed so stock writes cannot drift through a second PHP code path.

## Recommended Ownership

| Path | Purpose | Status |
| --- | --- | --- |
| `php_backend/` | Main PHP/MySQL application: public PHP pages, DB schema, bootstrap, config, helpers. | Primary |
| `php_backend/public/` | Browser-facing PHP entry points such as repair booking, wholesale checkout, and admin screens. | Primary |
| `php_backend/database/schema.sql` | Canonical MySQL schema for the commerce, repair, wholesale, payment, blog, and admin model. | Primary |
| `analytics_service/` | FastAPI analytics microservice for forecasts, reorder signals, retention cohorts, and dashboard charts. | Phase 2 service |
| `public/` | Static HTML/CSS/JS prototype and image assets. Good for design migration into PHP pages. | Prototype |
| `public/assets/` | Brand, repair, part, device, staff, and marketing images. | Shared assets |
| `backend/` | Older Node/Mongo API for auth and inventory experiments. Keep as reference while PHP services replace it. | Legacy/reference |
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
- Treat `backend/` as a legacy source of ideas, not a required runtime service for the target architecture.

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

- `admin_dashboard.php`: primary operations cockpit for bookings, parts, low stock, payments, wholesale approvals, and blog prompts.
- `admin_inventory.php`: stock, product, variant, and low-stock work.
- `admin_orders.php`: order list, status updates, invoice printing.
- `admin_payments.php`: payments-only ledger for finance review, M-Pesa receipts, checkout references, bank references, and verification state.
- `admin_repairs.php`: booking list, technician assignment, repair status.
- `admin_users.php`: customers, staff, permissions, wholesale approvals.
- `admin_blog.php`: category and post publishing.
- `admin_reports.php`: sales, stock movement, popular products, repair statistics.

## API Shape

PHP can expose JSON endpoints under `php_backend/public/api`. Keep endpoints grouped by domain:

- `api/products.php`
- `api/health.php`
- `api/orders.php`
- `api/bookings.php`
- `api/payments/mpesa_callback.php`
- `api/auth.php`
- `api/inventory.php`
- `api/reports.php`

## Target Architecture Alignment

The current target is:

- Client layer: current PHP pages, with future React admin SPA, mobile apps, and WhatsApp bot.
- API gateway: future `php_backend/public/api/` endpoints behind nginx and rate limiting.
- Service layer: PHP service classes for order, tracking, blog, notification, and payment behavior.
- Job queue: existing `payment_callback_queue`, plus inventory alert jobs and future SMS/reorder/analytics jobs.
- Data layer: MySQL primary today, Redis cache where available, and Python analytics over the MySQL data set.
- Infrastructure: XAMPP/cPanel/shared hosting now, VPS/nginx next, Docker/load balancer later.

Python is now present as `analytics_service/`; it supports diagnostics, forecasting, recommendations, and dashboard intelligence without taking over PHP checkout/admin flows.
