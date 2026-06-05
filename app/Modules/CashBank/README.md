# CashBank Module

Purpose: Own cash and bank account, receipt, payment, transfer, reconciliation, and cash bank report API routes.

Route ownership: `/cash-bank/*`.

Current HTTP controllers remain in `app/Http/Controllers/Api/CashBank`.

Current services remain in `app/Services/CashBank`.

Current models remain in `app/Models` or `app/Models/Tenant`.

Future migration plan: Move controllers and requests into this module in Phase M2, then move services behind explicit contracts in Phase M3.

Dependency notes: Accounting posting and transaction lifecycle integration should remain through shared services or contracts.
