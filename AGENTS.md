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

## Cross-Repository Audit-13 Remediation

Backend changes are authorized when required to close findings from
`/workspace/frontend/docs/audit_docs/audit-13-manual-frontend-audit-tracker-17-06-26.md`.

Before any Audit-13 backend change, also read:

```text
/workspace/frontend/docs/praproduction_docs/spec-37-audit-13-remediation.md
/workspace/frontend/docs/gap_docs/gap-10-audit-13-remediation-roadmap.md
/workspace/frontend/docs/prompt/prompt-guardrails-audit-13-implementation.md
```

Spec-37 is the cross-session contract and safety guardrail. Its tenancy, permission, accounting, inventory, lifecycle, testing, and verification rules apply to every Audit-13 backend change.

After implementation in each phase, run the mandatory validation gate from Spec-37 §17.1. A phase cannot be marked complete until every scoped finding has individual automated and runtime evidence, all findings are `verified`, and any regression introduced by the phase is fixed and reverified.

- Treat backend routes, FormRequests, Resources/serializers, services, and feature tests as one contract with the frontend service adapters.
- Do not preserve a broken API shape solely because the current frontend consumes it; define the canonical contract, update both repositories, and document the migration.
- Keep backward compatibility when external consumers may exist, or record an explicit breaking-contract decision.
- Never mutate production/live tenant data during verification. Use tests, isolated local databases, or intercepted browser mutations.
- For schema/data corrections, use migrations or explicit repair commands with tests; never edit SQLite/database files directly.
- Run the relevant Laravel feature tests and `vendor/bin/pint --test` after changes.

## Backend Docs Reading Order

Before backend audit, module planning, route work, migration work, or feature-gap analysis, read backend-local docs first:

1. `docs/backend-directory-tree.md`
2. `docs/backend-modular-monolith-plan.md`
3. `docs/backend-missing-modules-audit.md`

For fixed asset, opening balance, setup wizard, or period-end work, also read:

4. `docs/accounting-setup-and-fixed-assets-context.md`
5. The relevant file under `docs/implementation_plans/`, when it exists.

Use `/workspace/laravel_backend/docs/` as the source of truth for backend planning notes. Do not use `/workspace/docs` or `/workspace/frontend/docs` for backend gap status unless the user explicitly asks for historical/frontend context.
