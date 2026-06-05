# Settings Module

Purpose: Own company settings and company workflow API routes.

Route ownership: `/settings/company*`.

Current HTTP controllers remain in `app/Http/Controllers/Api/Settings`.

Current services remain in `app/Services/Settings`.

Current models remain in `app/Models`.

Future migration plan: Move controllers and requests into this module in Phase M2.

Dependency notes: Settings that affect transaction behavior should be exposed through shared policy or lifecycle services.
