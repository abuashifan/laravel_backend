# Period-End Processing Implementation Plan

Date: 2026-06-15
Status: planning
Context source: `docs/accounting-setup-and-fixed-assets-context.md`

## Purpose

Implement monthly Akhir Periode / period-end processing so recurring accounting routines can be posted by accounting period, not arbitrary date.

Period-End Processing belongs to the Accounting module. It acts as the orchestrator for period-end routines. The MVP routine is Fixed Asset depreciation/amortization.

## Dependencies

- Read `docs/accounting-setup-and-fixed-assets-context.md` before implementing this plan.
- Fixed Asset depreciation/amortization behavior follows `docs/implementation_plans/fixed-assets-implementation-plan.md`.
- Setup completion guard follows `docs/implementation_plans/setup-wizard-implementation-plan.md` when the Setup Wizard module exists.
- Use existing Accounting module route ownership under `/api/accounting/*`.
- Use existing `accounting_periods` and `fiscal_years` models/structure.
- Use existing period lock policy based on `fiscal_years.locked_until`.
- Use existing journal immutability, source links, document numbering, audit logging, and permission catalog patterns.

## Backend Ownership

Period-End Processing owns:

- Monthly period-end run orchestration.
- Period-end checklist generation.
- Routine registry and routine execution order.
- Period-end run status and routine status.
- Idempotency guard per company, period, and routine.
- Period-end audit trail.
- Reopen/reversal orchestration for supported routines.

Period-End Processing does not own:

- Fixed asset schedule calculation rules.
- Fixed asset depreciation run line details.
- Account mapping definitions.
- Fiscal year creation.
- Accounting period creation.
- Manual period lock management.

## Data Model

Create tenant tables:

- `period_end_runs`
- `period_end_run_routines`

### `period_end_runs`

Minimum fields:

- `id`
- `run_number`
- `accounting_period_id`
- `period_year`
- `period_month`
- `period`
- `status`
- `checklist_snapshot`
- `started_at`
- `completed_at`
- `failed_at`
- `reopened_at`
- `created_by`
- `completed_by`
- `reopened_by`
- `metadata`
- timestamps

Allowed statuses:

- `draft`
- `checking`
- `ready`
- `running`
- `completed`
- `failed`
- `reopened`
- `voided`

Rules:

- `period` uses `YYYY-MM`.
- `accounting_period_id` references the central `accounting_periods` record for the active company.
- `checklist_snapshot` stores the checklist result used for the run attempt.
- `run_number` uses document numbering. If no document type exists, add `period_end`.

### `period_end_run_routines`

Minimum fields:

- `id`
- `period_end_run_id`
- `routine_key`
- `routine_name`
- `status`
- `journal_entry_id`
- `started_at`
- `completed_at`
- `failed_at`
- `error_message`
- `metadata`
- timestamps

Allowed statuses:

- `pending`
- `running`
- `completed`
- `failed`
- `skipped`
- `reversed`

Unique guard:

- Prevent the same `routine_key` from being completed more than once for the same company and period.
- The guard may be implemented with a denormalized `period` column on routine rows, a unique index using the parent run, or a service-level lock plus database constraint.

## Period Selection

User chooses a month period, not a free date.

Accepted inputs:

- Query string `period=YYYY-MM` for status/checklist.
- Body `period=YYYY-MM` for run/reopen.
- Body `period_year` and `period_month` may also be accepted if existing request patterns prefer split fields.

Rules:

- Reject arbitrary date inputs such as `2026-03-15`.
- Resolve the selected month to the active company's central `accounting_periods` record.
- If the accounting period does not exist, return validation error.
- Do not auto-create accounting periods from period-end endpoints.
- Period-End must not run or reopen inside a closed fiscal year.

## API And Permissions

Minimum routes:

| Method | Path | Permission | Purpose |
| --- | --- | --- | --- |
| GET | `/api/accounting/period-end/status?period=YYYY-MM` | `period_end.view` | Return period-end state for selected period |
| GET | `/api/accounting/period-end/checklist?period=YYYY-MM` | `period_end.view` | Return readiness checklist and routine preview |
| POST | `/api/accounting/period-end/run` | `period_end.run` | Execute all enabled MVP routines for selected period |
| POST | `/api/accounting/period-end/reopen` | `period_end.reopen` | Reopen/reverse completed period-end when allowed |

Permission keys:

- `period_end.view`
- `period_end.run`
- `period_end.reopen`

Rules:

- Add permission keys to `config/permissions.php`.
- Sync permission keys to the permission catalog.
- Use `auth:sanctum`, `company.access`, and route-level `permission:*`.
- Running the fixed asset routine must also respect the `fixed_assets.depreciate` domain capability.
- Do not expose period-end routes under Fixed Assets.

## Checklist Flow

Checklist does not create journals.

Checklist calculates whether the selected period is ready to run period-end routines.

MVP checklist items:

- Accounting period exists.
- Fiscal year exists and is open.
- Period is not locked by `fiscal_years.locked_until`.
- Setup is finalized if the Setup Wizard module exists.
- Required fixed asset account mappings exist.
- Fixed asset routine has eligible schedule lines, or can safely report zero lines.
- No completed fixed asset depreciation routine already exists for the same period.
- No failed unreconciled period-end run blocks the same period.

Checklist response must include:

- `period`
- `status`
- `blocking_errors`
- `warnings`
- `routines`
- `can_run`

Routine preview fields:

- `routine_key`
- `routine_name`
- `status`
- `eligible_line_count`
- `estimated_amount`
- `blocking_errors`
- `warnings`

## Routine Registry

Create a service boundary/registry for period-end routines.

MVP registered routine:

- `fixed_asset_depreciation`

Future routine keys:

- `inventory_valuation_check`
- `bank_reconciliation_check`
- `accruals_deferrals`
- `tax_settlement`

Rules:

- MVP run executes all enabled MVP routines for the selected period.
- MVP does not allow users to manually select individual routines.
- Each routine exposes checklist/preview, run, and reversal capability metadata.
- Each routine must be idempotent for the selected company and period.

## Run Flow

`POST /api/accounting/period-end/run` accepts the selected period.

Preconditions:

- Period input is valid.
- Accounting period exists.
- Fiscal year is open.
- Period is not locked.
- Checklist has no blocking errors.
- User has `period_end.run`.
- Fixed asset routine can pass `fixed_assets.depreciate` capability check.

Behavior:

- Run checklist again inside a database transaction or guarded execution boundary.
- If blocking errors exist, reject run and store audit log.
- Create `period_end_runs` if no reusable failed/incomplete run exists.
- Store checklist snapshot on the run.
- Set run status to `running`.
- Create or reuse routine rows for all enabled MVP routines.
- Execute routines in registry order.
- Mark each routine as `completed`, `skipped`, or `failed`.
- If any routine fails, set run status `failed`, store error metadata, and do not close the accounting period.
- If all routines are `completed` or `skipped`, set run status `completed`.
- When run completes, mark the accounting period as closed or store metadata indicating period-end completion, following existing `AccountingPeriod` conventions.
- Write audit logs for attempted, completed, and failed runs.

Idempotency:

- A completed run for the same period must not create another journal.
- A completed routine for the same period must not be executed again.
- Retry after failed run executes only routines that are not already completed.

## Fixed Asset Routine

Routine key:

- `fixed_asset_depreciation`

Routine behavior:

- Load eligible fixed asset depreciation/amortization schedules for the selected period.
- Eligible schedule lines are schedule rows for the period that are not posted.
- Non-depreciable, impairment-only, and no-schedule assets are excluded.
- If no eligible lines exist, mark routine `skipped` or `completed` with zero amount and do not create a journal.
- If eligible lines exist, post depreciation/amortization journal.
- Create or link `fixed_asset_depreciation_runs`.
- Create or link `fixed_asset_depreciation_run_lines`.
- Mark schedule lines as posted.
- Store journal reference on the period-end routine and the fixed asset depreciation run.

Posting:

```text
Dr Depreciation Expense / Amortization Expense
Cr Accumulated Depreciation / Accumulated Amortization
```

Rules:

- Do not calculate daily proration.
- Follow fixed asset rule: first depreciation period is the month after `service_start_date`.
- Do not rewrite already posted schedules.
- Do not post duplicate journal entries for the same routine and period.

## Reopen / Reversal Flow

`POST /api/accounting/period-end/reopen` accepts:

- `period`
- `reason`

Preconditions:

- User has `period_end.reopen`.
- Selected period has a completed period-end run.
- No later period is already closed or completed.
- Fiscal year is not closed.
- Period is not manually locked by policy.
- All completed routines support reversal for MVP reopen.

Behavior:

- Write reopen attempted audit log.
- Reverse or void supported routine outputs.
- For fixed asset depreciation, create reversal journal or use existing controlled void/reversal mechanism.
- Mark fixed asset schedule lines and depreciation run records according to the reversal policy implemented by Fixed Assets.
- Mark routine rows `reversed`.
- Mark period-end run `reopened`.
- Reopen the related accounting period from `closed` to `open` if period-end was the actor that closed it.
- Store `reopened_at`, `reopened_by`, and reason metadata.
- Write reopen completed audit log.

Rejected reopen attempts:

- Later closed/completed period exists.
- Fiscal year is closed.
- Period is locked.
- Routine output cannot be safely reversed.
- Required `reason` is missing.

## Period Locks And Fiscal Year Rules

Rules:

- Period-End must not run if the selected period is on or before `fiscal_years.locked_until`.
- Period-End must not reopen if the selected period is on or before `fiscal_years.locked_until`.
- Period-End must not run or reopen in a fiscal year with status `closed`.
- Period-End must respect `accounting_periods.status`.
- If the period is already closed by period-end, run is idempotent and must not create new journals.
- If the period is manually locked, run and reopen are rejected.
- Override policy is not part of MVP.

## Failure Handling

Rules:

- Routine failure stores `error_message` and metadata on `period_end_run_routines`.
- Failed run stores failure metadata on `period_end_runs`.
- Failed run can be retried after the cause is fixed.
- Retry must not duplicate routines already completed.
- If a later multi-routine run partially completes and then fails, retry only runs the incomplete or failed routines.
- MVP has one routine, but table and service boundaries must support future multi-routine processing.

## Audit And Idempotency

Audit log required for:

- Checklist generated.
- Run attempted.
- Run completed.
- Run failed.
- Reopen attempted.
- Reopen completed.
- Reopen rejected.

Audit metadata should include:

- `period`
- `period_year`
- `period_month`
- `period_end_run_id`
- `routine_key`
- `journal_entry_id`
- `user_id`
- `reason`, when provided
- error details for failed/rejected actions

Idempotency requirements:

- Database and service guard must prevent duplicate completed routine per company/period/routine key.
- Journal source links must point to `period_end_run_routines`.
- Domain routine records, such as `fixed_asset_depreciation_runs`, must also be linked.
- Repeated completed-period run requests must return existing completed state or controlled validation response without duplicate accounting impact.

## Test Plan

Permission tests:

- Status and checklist require `period_end.view`.
- Run requires `period_end.run`.
- Reopen requires `period_end.reopen`.
- Fixed asset routine from period-end respects `fixed_assets.depreciate`.

Period validation tests:

- `period=2026-03` is valid.
- Free date format such as `2026-03-15` is rejected.
- Missing accounting period is rejected.
- Closed fiscal year rejects run and reopen.
- Locked period rejects run and reopen.

Checklist tests:

- Missing account mappings produce blocking errors.
- Fixed asset routine with no eligible lines previews zero or skipped safely.
- Duplicate completed routine is detected as blocking/idempotent state.
- Failed unreconciled run blocks or guides retry behavior.

Run tests:

- Run creates `period_end_runs` and `period_end_run_routines`.
- Fixed asset depreciation journal is created once.
- Second run for the same completed period does not create duplicate journal.
- Fixed asset schedule lines are marked posted.
- Zero-line routine does not create a journal.
- Failed routine stores status and error details.
- Retry after failure does not duplicate completed routines.

Reopen tests:

- Reopen completed period creates reversal or controlled void for fixed asset depreciation.
- Reopen marks routine as `reversed`.
- Reopen opens accounting period if period-end closed it.
- Reopen is rejected if a later period is completed or closed.
- Reopen is rejected in a closed fiscal year.
- Reopen requires a reason.

Audit tests:

- Checklist, run, failure, reopen, and rejected reopen write audit logs.
- Audit metadata includes period, routine key, user, journal id, and reason when available.

## Assumptions

- This plan changes only the Period-End Processing implementation plan document.
- `period_end_runs` and `period_end_run_routines` are tenant tables because period-end runs create tenant accounting impact.
- `accounting_periods` and `fiscal_years` continue using the existing backend structure.
- Period-End route prefix is `/api/accounting/period-end`.
- MVP runs all enabled routines for the selected period.
- MVP does not allow manual routine selection.
- Fixed asset depreciation/amortization is the only MVP routine.
- Fixed asset schedule and posting details follow `docs/implementation_plans/fixed-assets-implementation-plan.md`.
