# Mobimend PHP Backend

This folder is the recommended main implementation path for the Mobimend PHP/MySQL platform.

## Pages

- `public/setup_admin.php`
- `public/admin_products.php`
- `public/admin_orders.php`
- `public/admin_inventory.php`
- `public/admin_repairs.php`
- `public/repair.php`
- `public/accessories.php`
- `public/wholesale.php`
- `public/logout.php`

## Stack

- PHP 8.2+
- MySQL or MariaDB
- PDO
- PHP sessions for admin login

## Setup

1. Copy `.env.example` to `.env`.
2. Create the database and import `database/schema.sql`. For an existing database, apply `database/2026_05_27_inventory_safety_migration.sql` after backing up.
3. Point XAMPP or Apache at `php_backend/public`, or open the pages directly from your repo path under `htdocs`.
4. Start with `setup_admin.php` to create the first admin user.

## Notes

- `admin_products.php` manages categories, products, variants, cloud media URLs, stock adjustments, and synchronized inventory rows.
- `admin_orders.php` manages retail/wholesale orders, payment status, fulfillment status, and revenue/profit visibility.
- `admin_inventory.php` remains available for direct `inventory_items` edits, now transaction-wrapped with stock movement audit records.
- `admin_repairs.php` manages `repair_bookings`.
- `repair.php` creates repair bookings directly in MySQL.
- `accessories.php` reads live product variants, creates retail orders, payment records, stock movements, and inventory transactions.
- `wholesale.php` reads from `inventory_items`, enforces MOQ, creates wholesale orders, and records checkout activity in `stock_movements` plus `inventory_transactions`.

## Current Data Model

`database/schema.sql` is now the canonical schema for the larger platform:

- users, roles, customer addresses, technicians, and wholesale approvals
- products, categories, variants, inventory, stock movements, reorder points, and queued low-stock alert jobs
- repair services, repair bookings, technician assignment, and repair status history
- orders, order items, order status history, and payment state
- M-Pesa/bank-transfer payment records and verification fields
- blog categories/posts and a device repair knowledge base

See `../docs/PROJECT_STRUCTURE.md` for folder organization and `../docs/PRODUCT_MODEL.md` for the proposed repair-commerce model.
