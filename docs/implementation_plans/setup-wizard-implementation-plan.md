# Setup Wizard Implementation Plan

Date: 2026-06-15
Status: planning
Context source: `docs/accounting-setup-and-fixed-assets-context.md`

## Purpose

Implement the backend coordinator for initial company accounting setup.

The frontend may use `sessionStorage` for transient draft form state and current UI step state. The backend remains the authoritative source for official setup status, validation results, finalization, locks, and audit trail.

## Dependencies

- Read `docs/accounting-setup-and-fixed-assets-context.md` before implementing this plan.
- Setup Wizard is an orchestrator. It does not own Company Settings, Chart of Accounts, Account Mappings, Opening Balance, or Fixed Asset Register data.
- Opening Balance implementation details remain in `docs/implementation_plans/opening-balance-implementation-plan.md`.
- Fixed Assets implementation details remain in `docs/implementation_plans/fixed-assets-implementation-plan.md`.
- Period-end behavior remains in `docs/implementation_plans/period-end-processing-implementation-plan.md`.
- Use existing tenant request middleware for protected setup APIs: `auth:sanctum`, `company.access`, and route-level `permission:*`.
- Use the existing permission catalog and permission sync flow for setup permission keys.

## Backend Ownership

Backend owns:

- Official setup status per company.
- Current official setup step.
- Opening date/cutover date.
- Completed step tracking.
- Validation results.
- Opening balance preview orchestration.
- Finalization and duplicate-finalization protection.
- Locks for finalized setup-sensitive records.
- Reopen/correction gatekeeping.
- Audit logging.

Backend does not own:

- Every transient form field while the user is still typing.
- Frontend-only step navigation state stored in `sessionStorage`.
- Domain records already owned by other modules, such as COA, account mappings, opening balance lines, or fixed asset register records.

If frontend `sessionStorage` becomes stale, backend state always wins.

## Data Model

Create central table `company_setup_states`, not a tenant table.

Reason:

- Setup status belongs to the company record.
- It must be readable before or during tenant setup orchestration.
- It acts as the cross-module source of truth for setup progress and finalization state.

Minimum fields:

- `id`
- `company_id`
- `status`
- `current_step`
- `opening_date`
- `completed_steps`
- `validation_errors`
- `last_validated_at`
- `finalized_at`
- `finalized_by`
- `reopened_at`
- `reopened_by`
- `metadata`
- timestamps

Constraints and casts:

- Add a unique constraint on `company_id`.
- Cast `completed_steps`, `validation_errors`, and `metadata` as arrays/json.
- `opening_date` is nullable until the accounting setup step provides it.
- `finalized_by` and `reopened_by` reference central users when the schema supports it.

Allowed statuses:

- `not_started`
- `in_progress`
- `ready_to_finalize`
- `finalized`
- `reopened`

Status rules:

- If no setup state exists for a company, `GET /api/setup/status` creates or returns a default virtual state with `status = not_started`.
- Updating current step moves status from `not_started` to `in_progress`.
- Successful `validate-all` moves status to `ready_to_finalize`.
- Successful finalization moves status to `finalized`.
- Successful reopen moves status to `reopened`.
- `finalized` state cannot be downgraded by stale frontend requests.

## Wizard Steps

Canonical step keys:

- `company_profile`
- `module_selection`
- `accounting_settings`
- `chart_of_accounts`
- `account_mappings`
- `opening_fixed_assets`
- `opening_balance_preview`
- `final_review`
- `finalized`

Step behavior:

- `company_profile` validates company identity/profile data needed before accounting setup.
- `module_selection` validates enabled modules, including `fixed_asset_enabled`.
- `accounting_settings` validates base accounting settings and `opening_date`.
- `chart_of_accounts` validates that required COA foundation exists.
- `account_mappings` validates required mappings for enabled modules.
- `opening_fixed_assets` is active only when `fixed_asset_enabled = true`.
- `opening_balance_preview` validates opening balance preview and reconciliation.
- `final_review` requires all previous required steps to be valid.
- `finalized` is read-only and represents completed setup.

Required step rules:

- `opening_fixed_assets` is skipped when fixed assets are disabled.
- `opening_fixed_assets` is required when fixed assets are enabled.
- `opening_balance_preview` is always required before finalize.
- `final_review` cannot be completed until `validate-all` has passed.

## API And Permissions

Minimum routes:

| Method | Path | Permission | Purpose |
| --- | --- | --- | --- |
| GET | `/api/setup/status` | `setup.view` | Return official setup state for active company |
| GET | `/api/setup/steps` | `setup.view` | Return canonical steps with active/skipped/completed state |
| PATCH | `/api/setup/current-step` | `setup.edit` | Update official current step before finalization |
| POST | `/api/setup/validate-step` | `setup.validate` | Validate one step and store validation result |
| POST | `/api/setup/validate-all` | `setup.validate` | Validate all required steps before finalization |
| GET | `/api/setup/opening-balance/preview` | `setup.view` | Return opening balance preview with setup reconciliation results |
| POST | `/api/setup/finalize` | `setup.finalize` | Finalize setup and create/delegate opening balance journal |
| POST | `/api/setup/reopen` | `setup.reopen` | Reopen setup when allowed by correction policy |

Setup permission keys:

- `setup.view`
- `setup.edit`
- `setup.validate`
- `setup.finalize`
- `setup.reopen`

Permission rules:

- Add setup permission keys to `config/permissions.php`.
- Sync setup permissions into the permission catalog.
- Do not hardcode role names such as owner, admin, staff, or finance.
- Use route middleware `permission:{key}` for all setup routes.
- Opening fixed asset import endpoints remain owned by Fixed Assets and must require `fixed_assets.opening_import`.

## Validation And Preview Flow

`POST /api/setup/validate-step` accepts a step key and validates only that step against current backend data.

Behavior:

- Store validation errors by step in `company_setup_states.validation_errors`.
- Add successfully validated step keys to `completed_steps`.
- Remove a step from `completed_steps` if it becomes invalid.
- Update `last_validated_at`.
- Do not store full transient frontend form data.

`POST /api/setup/validate-all` validates every required step in canonical order.

Behavior:

- Skip `opening_fixed_assets` when fixed assets are disabled.
- Require `opening_fixed_assets` when fixed assets are enabled.
- Require account mappings needed by enabled modules.
- Require opening balance preview to reconcile before finalization.
- Set status to `ready_to_finalize` only when all required steps are valid.
- Keep status `in_progress` when any required step fails.

`GET /api/setup/opening-balance/preview` delegates to Opening Balance services and includes setup-specific reconciliation checks.

Preview must include:

- Opening balance journal preview lines.
- Fixed asset opening totals when fixed assets are enabled.
- Reconciliation status.
- Blocking errors that would prevent finalization.

Fixed asset reconciliation checks:

```text
sum(opening fixed asset cost) == opening balance fixed asset control account debit
sum(opening accumulated depreciation) == opening balance accumulated depreciation credit
sum(opening net book value) == cost - accumulated depreciation
```

If fixed asset totals do not reconcile, finalization is blocked.

## Finalization Flow

`POST /api/setup/finalize` finalizes initial company setup.

Preconditions:

- Setup state exists.
- Status is `ready_to_finalize`, or `validate-all` passes inside the finalization transaction.
- All required steps are valid.
- Required account mappings are complete.
- Opening balance preview reconciles.
- Fixed asset opening import totals reconcile when fixed assets are enabled.
- Company is not already finalized.

Behavior:

- Run final validation inside a database transaction.
- Create or delegate creation of exactly one opening balance journal.
- Lock opening balance records.
- Lock opening fixed asset import/register records that are part of initial setup.
- Store `finalized_at` and `finalized_by`.
- Set status to `finalized`.
- Set `current_step = finalized`.
- Write audit log.

Idempotency:

- A company with status `finalized` must not create another opening balance journal.
- Repeated finalize requests after success must return the existing finalized state or a controlled validation error, but must not duplicate accounting impact.

## Reopen / Correction Policy

`POST /api/setup/reopen` is available only through `setup.reopen`.

MVP policy:

- Reopen is allowed only when the company has no operational transactions after `opening_date`.
- If operational transactions exist, reopen is rejected.
- Later correction workflows can be designed separately for production corrections.

Behavior when reopen is allowed:

- Set status to `reopened`.
- Clear or update finalization locks according to domain services.
- Store `reopened_at` and `reopened_by`.
- Keep prior validation/finalization metadata in audit logs.
- Write audit log.

Operational transaction examples that should block reopen:

- Sales invoices or receipts after opening date.
- Purchase bills or payments after opening date.
- Cash/bank transactions after opening date.
- Manual journals after opening date, except the original opening balance journal.
- Inventory operational movements after opening date.
- Fixed asset capitalization/depreciation/disposal after setup finalization.

## Opening Fixed Asset Import Boundary

Setup Wizard coordinates opening fixed asset import, but does not own fixed asset register implementation.

Rules:

- Opening fixed asset import lives in the setup UX.
- Fixed Assets module creates opening asset register records.
- Opening fixed asset import requires `fixed_assets.opening_import`.
- Opening fixed asset import does not create a standalone GL journal.
- Opening fixed asset import feeds totals into opening balance preview.
- Opening Balance finalization posts the GL opening journal.
- Setup finalization must prevent duplicate manual entry of fixed asset control totals.

## Session Storage Guardrails

Frontend may use `sessionStorage` to preserve draft setup form data across refreshes.

Backend rules:

- Backend does not persist every draft field solely for refresh survival.
- Backend stores only official setup state, step progress, validation result, finalization metadata, lock metadata, and audit records.
- Requests from a stale frontend step must not move backend status backward.
- Requests that contradict finalized backend state must be rejected.
- `GET /api/setup/status` is the frontend's source of truth after refresh, login, company switch, or finalize.

## Audit And Locks

Audit log required for:

- Step validation.
- Validate all.
- Opening balance preview generation when used for final review.
- Finalization.
- Reopen.
- Opening fixed asset import changes.

Lock rules:

- Finalized setup locks opening balance records.
- Finalized setup locks opening fixed asset import records included in setup.
- Finalized setup prevents current-step edits.
- Finalized setup prevents opening date changes through setup APIs.
- Unlocking finalized setup-sensitive records requires `setup.reopen` and must pass reopen policy.

## Test Plan

Permission tests:

- Each setup endpoint enforces its mapped setup permission.
- Opening fixed asset import endpoint enforces `fixed_assets.opening_import`.
- Role names are never used as access checks.

State tests:

- Initial status is created or returned as `not_started` when company has no setup state.
- Updating current step before finalization sets status to `in_progress`.
- Current step can be updated before finalization.
- Current step cannot be updated after finalization.
- Stale step requests cannot downgrade backend state.

Validation tests:

- Required steps fail when underlying domain data is incomplete.
- `opening_fixed_assets` is skipped when fixed assets are disabled.
- `opening_fixed_assets` is required when fixed assets are enabled.
- `validate-all` sets `ready_to_finalize` only when all required steps pass.
- Failed validation stores errors by step.

Preview tests:

- Opening balance preview includes fixed asset totals when fixed assets are enabled.
- Mismatched fixed asset cost blocks finalization.
- Mismatched accumulated depreciation blocks finalization.
- Net book value mismatch blocks finalization.

Finalization tests:

- Finalize creates or delegates exactly one opening balance journal.
- Finalize locks opening balance and opening fixed asset import records.
- Finalize stores `finalized_at` and `finalized_by`.
- A second finalize request does not create a duplicate journal.
- Finalize is rejected when account mappings are incomplete.

Reopen tests:

- Reopen succeeds before operational transactions exist.
- Reopen is rejected after operational transactions exist.
- Reopen requires `setup.reopen`.
- Reopen writes audit log.

Session stale tests:

- Backend finalized status wins over frontend draft state.
- Stale frontend current step cannot move setup from `finalized` or `ready_to_finalize` back to `in_progress`.

## Assumptions

- This plan changes only the Setup Wizard implementation plan document.
- `company_setup_states` is created in the central database.
- Setup Wizard route prefix is `/api/setup`.
- Setup Wizard does not own COA, account mapping, opening balance, or fixed asset register persistence.
- Opening Balance owns the opening journal.
- Fixed Assets owns opening asset register records.
- Frontend `sessionStorage` is a convenience layer, not an authority for backend setup state.
