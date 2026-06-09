# TenantAppDevelopment Laravel Backend

Laravel API backend for TenantAppDevelopment. The app uses a central SQLite database for users,
companies, subscriptions, and access data, plus one tenant SQLite database per company for
accounting, master data, sales, purchase, cash-bank, and inventory records.

## Fresh Clone Local Setup

Run these commands from `laravel_backend`:

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed
php artisan tenant:migrate --all
php artisan tenant:seed-dummy 1 --period=2026-01
php artisan serve
```

Demo login:

```text
Email: admin@example.com
Password: password
```

`php artisan db:seed` creates the demo user, demo companies, company-user assignments, and tenant
database records/files. Tenant tables and demo tenant rows are separate on purpose:

- `php artisan tenant:migrate --all` creates tenant tables in every active tenant SQLite database.
- `php artisan tenant:seed-dummy 1 --period=2026-01` seeds compact demo data for company ID `1`.
- For the larger yearly trading demo, use `php artisan tenant:seed-demo-accounting-cycle --company-id=1 --year=2025 --reset-demo-data`.

Without the tenant migrate/seed steps, login and company selection can work while tenant endpoints
such as `/api/master-data/chart-of-accounts`, `/api/master-data/contacts`, and `/api/journals`
return database errors because the tenant SQLite tables or rows are missing.

## Local Verification

Useful checks:

```bash
php artisan config:clear
php artisan route:list --path=api
php artisan migrate:status
php artisan tenant:check-storage
php artisan test
```

Expected core API routes include:

- `GET /api/health`
- `POST /api/auth/login`
- `GET /api/auth/me`
- `GET /api/companies`
- `POST /api/companies/select`
- `GET /api/tenant-context-test`
- `GET /api/master-data/chart-of-accounts`
- `GET /api/master-data/contacts`
- `GET /api/master-data/payment-terms`
- `GET /api/master-data/units`
- `GET /api/master-data/departments`
- `GET /api/master-data/projects`
- `GET /api/master-data/account-mappings`
- `GET /api/journals`
- `GET /api/reports/*`
- `GET /api/sales/*`
- `GET /api/purchase/*`
- `GET /api/cash-bank/*`
- `GET /api/inventory/*`
- `GET /api/access/*`

Tenant-scoped API requests must send:

```text
Authorization: Bearer <token>
X-Company-ID: <active company id>
```

## Frontend

The Vue dev default proxies `/api` to `http://127.0.0.1:8000`. Keep the Laravel server on port
`8000`, or update `vue_frontend/.env` with the backend URL used locally.

## Notes

Database files are local development artifacts and should not be committed. Rebuild them with the
commands above after a fresh clone.
