# Tenant Module

Purpose: Own tenant context diagnostics and tenant boundary concerns.

Route ownership: `/tenant-context-test`.

Current HTTP controllers remain in `app/Http/Controllers/Api/Tenant`.

Current services remain in `app/Services/Tenant`.

Current models remain in `app/Models`.

Future migration plan: Move tenant controllers and requests into this module in Phase M2 while keeping tenant context stable for all modules.

Dependency notes: Tenant context is shared infrastructure. Modules should not duplicate tenant resolution or `X-Company-ID` behavior.
