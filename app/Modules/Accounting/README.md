# Accounting Module

Purpose: Own fiscal year status, fiscal year closing, and accounting period lock API routes.

Route ownership: `/accounting/*` and `/accounting/fiscal-year/status`.

Current HTTP controllers remain in `app/Http/Controllers/Api/Accounting`.

Current services remain in `app/Services/Accounting` and related transaction services.

Current models remain in `app/Models` or `app/Models/Tenant`.

Future migration plan: Move controllers and requests into this module in Phase M2, then expose posting and fiscal controls through contracts in Phase M3.

Dependency notes: Cross-module accounting integration should use contracts, shared transaction lifecycle services, or events.
