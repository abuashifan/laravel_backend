# Laravel Backend Project Rules

This is the Laravel backend project for the multi-tenant accounting ERP.

## RTK Token Saving Rules

Use RTK for file inspection, git status, diffs, search results, logs, migrations, seeders, routes, controllers, models, services, tests, and artisan output.

Prefer:
- `rtk ls .`
- `rtk read path/to/file`
- `rtk grep "Route::" routes`
- `rtk grep "class .*Controller" app/Http/Controllers`
- `rtk grep "Schema::create" database/migrations`
- `rtk git status`
- `rtk git diff`

Do not dump huge Laravel logs, vendor folders, storage logs, cache folders, or full migration/controller trees into context.
Summarize first, then inspect only relevant files.

## Backend Guardrails

Preserve existing API contracts, tenant middleware, permissions, posting rules, journal immutability, and rollback/void behavior.
Do not refactor unrelated modules unless the task explicitly requires it.
After backend changes, run the narrowest relevant verification command available.
