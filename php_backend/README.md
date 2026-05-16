# Mobimend PHP Backend

This folder is the recommended main implementation path for the Mobimend PHP/MySQL platform.

## Pages

- `public/setup_admin.php`
- `public/admin_inventory.php`
- `public/admin_repairs.php`
- `public/repair.php`
- `public/wholesale.php`
- `public/logout.php`

## Stack

- PHP 8.2+
- MySQL or MariaDB
- PDO
- PHP sessions for admin login

## Setup

1. Copy `.env.example` to `.env`.
2. Create the database and import `database/schema.sql`.
3. Point XAMPP or Apache at `php_backend/public`, or open the pages directly from your repo path under `htdocs`.
4. Start with `setup_admin.php` to create the first admin user.

## Notes

- `admin_inventory.php` manages `inventory_items`.
- `admin_repairs.php` manages `repair_bookings`.
- `repair.php` creates repair bookings directly in MySQL.
- `wholesale.php` reads from `inventory_items` and records checkout activity in `inventory_transactions`.

## Current Data Model

`database/schema.sql` is now the canonical schema for the larger platform:

- users, roles, customer addresses, technicians, and wholesale approvals
- products, categories, variants, inventory, and inventory movement
- repair services, repair bookings, technician assignment, and repair status history
- orders, order items, order status history, and payment state
- M-Pesa/bank-transfer payment records and verification fields
- blog categories/posts and a device repair knowledge base

See `../docs/PROJECT_STRUCTURE.md` for folder organization and `../docs/PRODUCT_MODEL.md` for the proposed repair-commerce model.
