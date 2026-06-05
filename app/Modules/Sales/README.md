# Sales Module

Purpose: Own sales workflow API routes, AR routes, and sales source document routes.

Route ownership: `/sales/*`.

Current HTTP controllers remain in `app/Http/Controllers/Api/Sales` and shared transaction controllers.

Current services remain in `app/Services/Sales`.

Current models remain in `app/Models` or `app/Models/Tenant`.

Future migration plan: Move controllers and requests into this module in Phase M2, then move services behind explicit contracts in Phase M3.

Dependency notes: Inventory, accounting, cash bank, and source document integration should use contracts, events, or shared services.
