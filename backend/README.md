# Mobimend Backend

## Setup
1. Create `.env` from `.env.example` and set real values.
2. Install dependencies:
   - `npm install`
3. Seed the first admin user:
   - `npm run seed:admin`
4. Start the server:
   - `npm run dev`

## Auth
- `POST /api/auth/login` expects `email` and `password`.
- `POST /api/auth/register` is disabled unless `ADMIN_SETUP_TOKEN` is set.
  - Provide the token via header `x-admin-setup-token`.

## Repairs
- `POST /api/repairs` creates a booking (public).
- Admin endpoints require `Authorization: Bearer <token>`.

## Inventory
- Inventory write endpoints require a bearer token with `admin`, `super_admin`, or `inventory_manager` role.
- Stock decrements run in MongoDB transactions, write `StockMovement` audit records, and enqueue `InventoryAlertJob` low-stock work instead of sending notifications inline.
- MongoDB transactions require a replica set or compatible managed MongoDB deployment.
