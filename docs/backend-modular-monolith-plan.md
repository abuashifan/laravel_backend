# Backend Modular Monolith Plan

## Current Backend Condition

The backend is a Laravel API using Sanctum, central database records, and one tenant database per company. Tenant-aware requests use `X-Company-ID` and the existing middleware stack remains `auth:sanctum`, `company.access`, and `permission`.

The codebase is already partially modular: API controllers, form requests, and services are grouped by domain under `app/Http/Controllers/Api`, `app/Http/Requests`, and `app/Services`. Models remain centralized in `app/Models` and `app/Models/Tenant`. Migrations are already separated between central and tenant migration folders.

## Target Structure

```text
app/
  Modules/
    Access/
    Accounting/
    Auth/
    CashBank/
    Companies/
    Inventory/
    Journal/
    MasterData/
    Purchase/
    Reports/
    Sales/
    Settings/
    Tenant/
  Shared/
    Audit/
    Api/
    DocumentNumbering/
    SourceDocument/
    TransactionLifecycle/
    Support/
```

Phase M1 only introduces this structure and moves route ownership into module route files. Controllers, requests, services, models, migrations, tenant behavior, permission keys, API URLs, and response contracts are unchanged.

## Module Ownership Map

| Module | Owns |
| --- | --- |
| Access | `/access/*` users, roles, invitations, permission catalog, access audit routes |
| Auth | `/auth/*` authentication routes and authenticated permission lookup |
| Companies | `/companies` and `/companies/select` company selection routes |
| Tenant | `/tenant-context-test` tenant context diagnostics |
| Settings | `/settings/company*` company setting and workflow routes |
| MasterData | `/master-data/*` chart of accounts, contacts, payment terms, units, products, warehouses, dimensions, account mappings |
| Accounting | `/accounting/*` fiscal year status, closing, and period lock routes |
| Journal | `/journals*` manual journal entry routes |
| Reports | `/reports/*` financial report routes |
| Sales | `/sales/*` sales workflow, AR, and sales source document routes |
| Purchase | `/purchase/*` purchase workflow, AP, and purchase source document routes |
| CashBank | `/cash-bank/*` cash receipt, cash payment, bank transfer, reconciliation, and cash bank report routes |
| Inventory | `/inventory/*` stock balances, stock movement, stock adjustment, opname, valuation, and inventory report routes |

## Shared Services Map

| Shared Area | Current Location | Notes |
| --- | --- | --- |
| Audit | `app/Services/Audit`, `app/Support/Audit` | Shared audit logging and audit constants |
| DocumentNumbering | `app/Services/DocumentNumbering`, `config/document_numbers.php` | Cross-domain document numbering rules |
| TransactionLifecycle | `app/Services/Transactions`, `app/Traits/HasTransactionLifecycle`, `config/transaction_lifecycle.php` | Shared lifecycle status and guard behavior |
| SourceDocument | `app/Http/Controllers/Api/Transactions`, source link support | Shared source document picker and source link behavior |
| ApiResponse | `app/Support/Api`, `app/Traits/ApiResponse`, `config/api_errors.php` | Shared API response and error contract |
| TenantContext | `app/Services/Tenant` | Shared active company and tenant database context |
| Permission | `app/Services/Permissions`, `config/permissions.php` | Shared permission catalog and permission evaluation |

## Dependency Rules

Modules should communicate only through application service contracts, shared services, events/listeners, DTO/data objects, or explicit integration services. New direct coupling between modules should be avoided. Tenant context and permission behavior remain shared infrastructure, not module-private state.

## Migration Plan

Phase M1: Create module/shared directories, split API routes into module route files, keep URLs and middleware unchanged, document ownership, and add route regression coverage.

Phase M2: Move HTTP layer per module by migrating controllers and requests into `app/Modules/{Module}/Http` gradually. Keep compatibility and route contracts stable.

Phase M3: Move service layer per module from `app/Services/{Domain}` into module service folders. Introduce interfaces/contracts for cross-module integration.

Phase M4: Evaluate model placement. Tenant models may stay centralized or move gradually with compatibility layers. Avoid a big-bang model migration.

Phase M5: Introduce event/application boundaries to reduce direct coupling between Sales, Purchase, Inventory, Accounting, CashBank, and Reports.

Phase M6: Add architecture tests for module import rules, cross-module contracts, route isolation, and tenant-aware enforcement.

## Regression Checklist

- `php artisan config:clear` succeeds.
- `php artisan route:clear` succeeds.
- `php artisan route:list --path=api` succeeds.
- Route count before and after route split is unchanged.
- Critical endpoints remain registered: `/api/health`, `/api/auth/login`, `/api/companies`, `/api/tenant-context-test`, `/api/master-data/products`, `/api/journals`, `/api/reports/profit-loss`, `/api/sales/invoices`, `/api/purchase/bills`, `/api/cash-bank/accounts`, `/api/inventory/stock-balances`, `/api/access/users`.
- Middleware remains unchanged for protected routes: `auth:sanctum`, `company.access`, and route-level `permission:*`.
- No frontend files changed.
- No migrations or database schema changed.
- No business service behavior changed.
