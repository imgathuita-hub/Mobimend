# Target Architecture Audit

Audit date: 2026-06-16

## Current Alignment

- Client layer: PHP public and admin pages are active and cover repair, shop, wholesale, blog, account, and tracking.
- Service layer: PHP service classes exist for payments, M-Pesa callbacks, inventory ledger, analytics calls, and callback queues.
- Job queue: `payment_callback_queue` exists; `inventory_alert_jobs` covers low-stock/reorder alerts.
- Data layer: `schema.sql` is the canonical MySQL schema; optional Redis caching is used by dashboard/analytics code.
- Analytics: `analytics_service/` provides FastAPI endpoints for forecasts, reorder signals, cohorts, and dashboard charts.

## Fixed During This Audit

- `InventoryLedger::mirrorTransaction()` now stores absolute item counts in `inventory_transactions.quantity`. The signed stock delta remains in `stock_movements.quantity_delta`, which keeps movement history accurate while analytics can safely sum sold units.
- Root documentation now describes the PHP + Python target architecture and marks Node/MongoDB as legacy/reference.
- Project structure documentation now includes the FastAPI analytics service and the target gateway/service/queue/data layers.
- `php_backend/public/api/health.php` now provides the first REST gateway endpoint for PHP, database, and analytics health.

## Remaining Risks

- `backend/` duplicates auth and inventory concepts that now live in PHP/MySQL. Keep it out of the main runtime unless there is a deliberate migration plan.
- `admin_dashboard.php` still owns local dashboard cache helpers while `AnalyticsClient` owns analytics cache logic. This is acceptable short-term, but a shared cache helper would reduce duplication.
- There is no PHP test suite in the repo yet, despite PHPUnit being listed as a dev dependency.
- Python compile checks were blocked by Windows file permission errors on bytecode cache writes; run FastAPI checks in a writable virtualenv or clean the locked `__pycache__`.
- The REST API gateway layer now has a health endpoint, but domain endpoints for orders, tracking, payments, inventory, blog, and auth are still future Phase 3 work.
- `php_backend/src/Repositories/accessoriesrepository.php` and `wholesalerepository.php` do not match PSR-4 class filename casing. Windows allowed them, but Linux/Composer deployments should rename them to `AccessoriesRepository.php` and `WholesaleRepository.php`.

## Suggested Next Steps

1. Add a small `Mobimend\Services\Cache` helper and move dashboard Redis/APCu/file cache behavior into it.
2. Add `php_backend/public/api/` endpoints for orders, tracking, payments, inventory, blog, and auth.
3. Add queue workers for `inventory_alert_jobs`, SMS/email notifications, and analytics event ingestion.
4. Add focused tests around `InventoryLedger`, `MpesaCallbackProcessor`, and `AnalyticsClient`.
5. Archive or freeze `backend/` after confirming no active route depends on it.
