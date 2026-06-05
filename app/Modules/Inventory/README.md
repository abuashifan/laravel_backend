# Inventory Module

Purpose: Own stock balance, stock movement, stock adjustment, stock opname, valuation, and inventory report API routes.

Route ownership: `/inventory/*`.

Current HTTP controllers remain in `app/Http/Controllers/Api/Inventory`.

Current services remain in `app/Services/Inventory`.

Current models remain in `app/Models` or `app/Models/Tenant`.

Future migration plan: Move controllers and requests into this module in Phase M2, then move inventory services behind explicit contracts in Phase M3.

Dependency notes: Sales, purchase, accounting, and cash bank integrations should use contracts, events, or shared transaction lifecycle services.
