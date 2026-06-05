# Access Module

Purpose: Own access management API routes for company users, roles, invitations, permission catalog, and access audit.

Route ownership: `/access/*`.

Current HTTP controllers remain in `app/Http/Controllers/Api/Access`.

Current services remain in `app/Services/Permissions` and related shared services.

Current models remain in `app/Models` or `app/Models/Tenant`.

Future migration plan: Move controllers and requests into this module in Phase M2, then move domain services behind explicit contracts in Phase M3.

Dependency notes: Keep permission checks through shared permission services and avoid direct coupling to business modules.
