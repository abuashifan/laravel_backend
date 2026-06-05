# Journal Module

Purpose: Own manual journal entry API routes.

Route ownership: `/journals*`.

Current HTTP controllers remain in `app/Http/Controllers/Api/Journal`.

Current services remain in `app/Services/Journal`.

Current models remain in `app/Models` or `app/Models/Tenant`.

Future migration plan: Move controllers and requests into this module in Phase M2, then move journal services behind explicit contracts in Phase M3.

Dependency notes: Posting, document numbering, and lifecycle rules should remain shared or contract-based.
