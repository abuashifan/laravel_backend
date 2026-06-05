# Companies Module

Purpose: Own company listing and company selection API routes.

Route ownership: `/companies` and `/companies/select`.

Current HTTP controllers remain in `app/Http/Controllers/Api/Companies`.

Current services remain in `app/Services/Companies`.

Current models remain in `app/Models`.

Future migration plan: Move controllers and requests into this module in Phase M2.

Dependency notes: Tenant selection must continue to use the existing tenant context and company access rules.
