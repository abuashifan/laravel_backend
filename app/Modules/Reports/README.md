# Reports Module

Purpose: Own financial report API routes.

Route ownership: `/reports/*`.

Current HTTP controllers remain in `app/Http/Controllers/Api/Reports`.

Current services remain in `app/Services/Reports`.

Current models remain in `app/Models` or `app/Models/Tenant`.

Future migration plan: Move controllers and requests into this module in Phase M2, then keep report query services behind explicit reporting contracts in Phase M3.

Dependency notes: Reports should read through report/query services and avoid changing transactional module state.
