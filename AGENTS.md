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

## Backend Docs Reading Order

Before backend audit, module planning, route work, migration work, or feature-gap analysis, read backend-local docs first:

1. `docs/backend-modular-monolith-plan.md`
2. `docs/backend-missing-modules-audit.md`

For fixed asset, opening balance, setup wizard, or period-end work, also read:

3. `docs/accounting-setup-and-fixed-assets-context.md`
4. The relevant file under `docs/implementation_plans/`, when it exists.

Use `/workspace/laravel_backend/docs/` as the source of truth for backend planning notes. Do not use `/workspace/docs` or `/workspace/frontend/docs` for backend gap status unless the user explicitly asks for historical/frontend context.
