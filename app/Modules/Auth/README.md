# Auth Module

Purpose: Own authentication API routes and authenticated permission lookup.

Route ownership: `/auth/*`.

Current HTTP controllers remain in `app/Http/Controllers/Api/Auth`.

Current services remain in their existing service folders.

Current models remain in `app/Models`.

Future migration plan: Move auth controllers and requests into this module in Phase M2.

Dependency notes: Keep Sanctum behavior unchanged and route protected permission lookup through shared permission services.
