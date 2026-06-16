# Mobimend

Mobimend is a phone care platform for repair bookings, accessories sales, wholesale parts, payments, customer tracking, and admin operations.

The target architecture is a PHP/MySQL application extracted into clear service boundaries, with a Python analytics microservice for forecasting and dashboard intelligence. The older Node/MongoDB service remains in the repository as legacy/reference code, but new product work should land in `php_backend/` and `analytics_service/`.

## Key Features

- **Repair Management:** Customers book repairs; admins manage booking status, parts blockers, and technician workflow.
- **Inventory Control:** Retail and wholesale stock tracking with movement audit records and low-stock alert jobs.
- **E-Commerce:** Accessories cart/checkout plus a wholesale desk with MOQ rules.
- **Admin Operations Hub:** `admin_dashboard.php` centralizes bookings, payments, inventory, wholesale approvals, blog prompts, and predictive signals.
- **Payments and Tracking:** M-Pesa, bank transfer, and cash payment records with public order/repair tracking.
- **Predictive Analytics:** FastAPI service for revenue forecasts, reorder signals, retention cohorts, and dashboard charts.

## Project Structure

- `php_backend/`: Primary PHP/MySQL application and service layer.
- `analytics_service/`: FastAPI analytics microservice used by the PHP admin dashboard.
- `backend/`: Legacy Node.js/Express/MongoDB service kept for reference while behavior is consolidated into PHP.
- `docs/`: Architecture, product model, and audit documentation.
- `storage/`: Runtime cache/log/upload storage.

## Tech Stack

- Primary application: PHP 8.0+ locally, PHP 8.2+ recommended, MySQL/MariaDB, PDO.
- Analytics service: Python, FastAPI, SQLAlchemy, pandas.
- Cache: Redis when available, file/APCu fallbacks in PHP.
- Frontend: HTML, CSS, JavaScript.
- Legacy reference: Node.js, Express.js, MongoDB, Mongoose.

## Setup

### PHP Backend

1. Copy `php_backend/.env.example` to `php_backend/.env`.
2. Update database credentials and `APP_URL`.
3. Create the database and import `php_backend/database/schema.sql`.
4. Open `php_backend/public/setup_admin.php` and create the first admin user with `ADMIN_SETUP_TOKEN`.
5. Serve `php_backend/public/` through Apache/XAMPP or nginx.

### Python Analytics Service

1. Go to `analytics_service/`.
2. Create a virtual environment and install dependencies:

```powershell
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
```

3. Copy `analytics_service/.env.example` to `analytics_service/.env` or export `DATABASE_URL`.
4. Start the service:

```powershell
uvicorn main:app --host 127.0.0.1 --port 8001
```

The PHP dashboard reads `ANALYTICS_API_BASE`, defaulting to `http://localhost:8001`.
