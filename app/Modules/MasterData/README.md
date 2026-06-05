# MasterData Module

Purpose: Own master data API routes for chart of accounts, contacts, payment terms, units, product categories, products, warehouses, departments, projects, and account mappings.

Route ownership: `/master-data/*`.

Current HTTP controllers remain in `app/Http/Controllers/Api/MasterData`.

Current services remain in `app/Services/MasterData` and `app/Services/AccountMapping`.

Current models remain in `app/Models` or `app/Models/Tenant`.

Future migration plan: Move controllers and requests into this module in Phase M2, then move services behind explicit contracts in Phase M3.

Dependency notes: Master data should be consumed through explicit services or DTOs when other modules need integration.
