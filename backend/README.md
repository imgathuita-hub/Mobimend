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
