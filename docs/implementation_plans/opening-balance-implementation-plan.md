# Opening Balance Implementation Plan

Date: 2026-06-15
Status: planning
Context source: `docs/accounting-setup-and-fixed-assets-context.md`

## Purpose

Implement user-facing Opening Balance APIs and persistence as the accounting source of truth for initial company balances.

Opening Balance owns the final GL opening journal. Setup Wizard may orchestrate preview and finalization, and Fixed Assets may provide opening fixed asset register totals, but neither module should create a separate opening GL journal.

## Dependencies

- Read `docs/accounting-setup-and-fixed-assets-context.md` before implementing this plan.
- Reuse existing `OpeningBalanceService`, `OpeningBalanceValidator`, `OpeningBalanceBatch`, `OpeningBalanceLine`, and `config/opening_balance.php`.
- Use existing `opening_balance` document number, source link, source module, and account mapping config.
- Use existing journal posting, immutability, void/reversal, audit log, and tenant middleware patterns.
- Setup Wizard orchestration follows `docs/implementation_plans/setup-wizard-implementation-plan.md`.
- Fixed asset opening import follows `docs/implementation_plans/fixed-assets-implementation-plan.md`.

## Backend Ownership

Opening Balance owns:

- Opening balance batch persistence.
- Opening balance line persistence.
- Opening balance validation.
- Opening balance preview.
- Final opening journal creation.
- Duplicate opening journal prevention.
- Opening balance lock after finalization.
- Opening balance correction/reopen gatekeeping.

Opening Balance does not own:

- Fixed asset register records.
- Opening fixed asset import screens.
- Setup Wizard step state.
- Company setup finalization status.
- COA or account mapping master data.

## Data Model

Create tenant tables:

- `opening_balance_batches`
- `opening_balance_lines`

### `opening_balance_batches`

Minimum fields:

- `id`
- `batch_number`
- `opening_date`
- `fiscal_year`
- `type`
- `status`
- `description`
- `total_debit`
- `total_credit`
- `difference`
- `journal_entry_id`
- `validated_at`
- `validated_by`
- `posted_at`
- `posted_by`
- `locked_at`
- `locked_by`
- `reopened_at`
- `reopened_by`
- `metadata`
- timestamps

Allowed statuses:

- `draft`
- `validated`
- `posted`
- `locked`
- `reopened`
- `voided`

Rules:

- Only one active posted/locked opening balance batch is allowed per tenant/company.
- `batch_number` uses document numbering. Use existing `opening_balance` document type.
- `type` defaults to `standard`.
- `journal_entry_id` points to the generated opening journal.
- `locked` means the batch was finalized through setup or explicit opening balance finalization.

### `opening_balance_lines`

Minimum fields:

- `id`
- `opening_balance_batch_id`
- `account_id`
- `account_code`
- `account_name`
- `account_type`
- `debit`
- `credit`
- `description`
- `source_type`
- `source_id`
- `source_line_id`
- `is_system_generated`
- `metadata`
- timestamps

Rules:

- A line cannot have both debit and credit.
- A line cannot have negative debit or credit.
- Zero lines are ignored or rejected according to request validation policy.
- Fixed asset system-generated lines must be marked with `is_system_generated = true`.
- Manual duplicate entry for fixed asset control accounts is blocked when fixed asset opening totals already generate those lines.

## API And Permissions

Minimum routes:

| Method | Path | Permission | Purpose |
| --- | --- | --- | --- |
| GET | `/api/opening-balance/status` | `opening_balance.view` | Return current opening balance status |
| GET | `/api/opening-balance/batches` | `opening_balance.view` | List opening balance batches |
| POST | `/api/opening-balance/batches` | `opening_balance.manage` | Create draft batch |
| GET | `/api/opening-balance/batches/{id}` | `opening_balance.view` | Show batch with lines and journal link |
| PATCH | `/api/opening-balance/batches/{id}` | `opening_balance.manage` | Update draft/reopened batch header |
| PUT | `/api/opening-balance/batches/{id}/lines` | `opening_balance.manage` | Replace draft/reopened manual lines |
| POST | `/api/opening-balance/batches/{id}/validate` | `opening_balance.validate` | Validate batch and store validation result |
| GET | `/api/opening-balance/batches/{id}/preview` | `opening_balance.view` | Preview final journal and reconciliation |
| POST | `/api/opening-balance/batches/{id}/post` | `opening_balance.post` | Post opening journal outside setup flow when allowed |
| POST | `/api/opening-balance/batches/{id}/lock` | `opening_balance.lock` | Lock finalized opening balance |
| POST | `/api/opening-balance/batches/{id}/reopen` | `opening_balance.reopen` | Reopen when correction policy allows |

Permission keys:

- `opening_balance.view`
- `opening_balance.manage`
- `opening_balance.validate`
- `opening_balance.post`
- `opening_balance.lock`
- `opening_balance.reopen`

Rules:

- Add permission keys to `config/permissions.php`.
- Sync permission keys to the permission catalog.
- Use `auth:sanctum`, `company.access`, and route-level `permission:*`.
- Do not hardcode user type or role names.
- Setup Wizard may call/delegate preview/finalization services, but direct API access still follows Opening Balance permissions.

## Batch And Line Flow

Draft flow:

- Create one draft batch for the company setup/opening date.
- Add or replace manual lines while status is `draft` or `reopened`.
- Do not allow line edits when status is `posted`, `locked`, or `voided`.
- Header `opening_date` must align with setup wizard opening date when setup wizard is active.

Line rules:

- `account_id` is required for persisted lines.
- `account_type` should be stored as a snapshot for validation/reporting.
- Real account types are allowed by default: asset, liability, equity.
- Nominal account types are rejected unless `opening_balance.allow_nominal_accounts_opening_balance` is explicitly enabled.
- Batch must balance unless `opening_balance.allow_unbalanced_opening_balance` is explicitly enabled.

Totals:

- Persist `total_debit`, `total_credit`, and `difference` on the batch after save/validate.
- `difference = total_debit - total_credit`.
- A balanced batch has difference within existing `OpeningBalanceBatch::isBalanced()` tolerance.

## Validation And Preview Flow

Validation uses the existing `OpeningBalanceValidator` and adds setup/fixed asset reconciliation checks.

Validation must check:

- Minimum non-zero lines.
- No line has both debit and credit.
- No negative debit or credit.
- Account type is allowed.
- Batch balances.
- Required account mappings exist.
- Only one active posted/locked opening balance exists.
- Fixed asset generated totals reconcile when fixed assets are enabled.

Preview must return:

- Batch header.
- Manual lines.
- System-generated lines, including fixed asset totals when applicable.
- Total debit.
- Total credit.
- Difference.
- Validation result.
- Blocking errors.
- Warnings.
- Journal payload preview.

Preview must not create a journal.

## Opening Fixed Asset Integration

Opening fixed asset import belongs to Fixed Assets and may be shown inside Setup Wizard.

Rules:

- Fixed Assets creates opening asset register records.
- Fixed Assets provides opening totals to Opening Balance preview.
- Opening fixed asset import does not create a standalone GL journal.
- Opening Balance finalization creates the GL opening journal that includes fixed asset totals.

Required reconciliation:

```text
sum(opening fixed asset cost) == opening balance fixed asset control account debit
sum(opening accumulated depreciation) == opening balance accumulated depreciation credit
sum(opening net book value) == cost - accumulated depreciation
```

Duplicate prevention:

- If fixed asset opening totals generate system lines, manual lines to the same fixed asset control accounts are blocked unless a future privileged override is explicitly designed.
- This prevents the same fixed asset opening value from entering GL twice.

## Finalization And Journal Posting

Opening Balance finalization may be triggered by Setup Wizard or by direct Opening Balance API when allowed.

Preconditions:

- Batch exists and is not `posted`, `locked`, or `voided`.
- Batch validates successfully.
- Opening date is valid.
- Batch balances.
- Required mappings exist.
- Fixed asset totals reconcile if fixed assets are enabled.
- No active posted/locked opening balance journal already exists for the company.

Posting behavior:

- Generate or use `batch_number`.
- Build journal payload through `OpeningBalanceService::prepareJournalPayload()`.
- Create exactly one posted opening journal.
- Set journal source fields:
  - `source_type = opening_balance`
  - `source_module = opening_balance`
  - `source_number = batch_number`
  - `source_id = opening_balance_batches.id`
- Store `journal_entry_id`.
- Set status to `posted`.
- Store `posted_at` and `posted_by`.
- Write audit log.

Locking behavior:

- Setup Wizard finalization should lock the opening balance batch after posting.
- Locking sets status `locked`, `locked_at`, and `locked_by`.
- Locked opening balance cannot be edited, reposted, or deleted.

Idempotency:

- Repeated finalization/post request must not create another journal.
- If the batch is already posted/locked, return existing state or controlled validation response.
- Database/service guard must prevent more than one active posted/locked opening balance per company.

## Reopen / Correction Policy

MVP reopen is guarded and conservative.

Reopen is allowed only when:

- User has `opening_balance.reopen`.
- Batch is `posted` or `locked`.
- No operational transactions exist after `opening_date`.
- Setup Wizard reopen policy allows correction if setup was finalized.
- Opening journal can be safely voided/reversed using existing journal rules.

Reopen behavior:

- Write reopen attempted audit log.
- Void or reverse the opening journal using existing controlled journal mechanism.
- Set batch status to `reopened`.
- Store `reopened_at` and `reopened_by`.
- Unlock lines for correction.
- Keep prior journal reference and correction metadata for audit.

Reopen is rejected when:

- Sales, purchase, cash/bank, inventory, fixed asset, period-end, or manual journal transactions exist after opening date.
- Fiscal year is closed.
- Period is locked.
- Opening journal cannot be safely reversed.
- Reopen reason is missing.

Production correction workflows can be designed later for companies that already have operational activity.

## Setup Wizard Boundary

Setup Wizard coordinates initial setup but does not own opening balance records.

Rules:

- Setup Wizard uses Opening Balance preview during `opening_balance_preview`.
- Setup Wizard finalization delegates posting to Opening Balance.
- Setup Wizard finalization must create/delegate exactly one opening balance journal.
- Setup Wizard finalization locks opening balance and opening fixed asset import records.
- Setup Wizard must treat Opening Balance validation errors as blocking errors.

## Audit And Locks

Audit log required for:

- Batch created.
- Batch updated.
- Lines replaced.
- Validation run.
- Preview generated for final review.
- Posting attempted.
- Posting completed.
- Posting rejected.
- Lock completed.
- Reopen attempted.
- Reopen completed.
- Reopen rejected.

Audit metadata should include:

- `opening_balance_batch_id`
- `batch_number`
- `opening_date`
- `journal_entry_id`
- `user_id`
- `reason`, when provided
- blocking errors for rejected actions

Lock rules:

- Posted/locked batches cannot be edited.
- Locked batches cannot be reopened without `opening_balance.reopen`.
- Setup-finalized opening balance cannot be unlocked unless Setup Wizard reopen policy also allows it.

## Test Plan

Permission tests:

- Status/list/show/preview require `opening_balance.view`.
- Create/update/lines require `opening_balance.manage`.
- Validate requires `opening_balance.validate`.
- Post requires `opening_balance.post`.
- Lock requires `opening_balance.lock`.
- Reopen requires `opening_balance.reopen`.
- Role names are never used as access checks.

Batch and validation tests:

- Draft batch can be created.
- Draft lines can be replaced.
- Posted/locked lines cannot be edited.
- Line with both debit and credit is rejected.
- Negative debit or credit is rejected.
- Nominal accounts are rejected by default.
- Unbalanced batch is rejected by default.
- Balanced real-account batch validates.

Preview tests:

- Preview returns journal payload without creating journal.
- Preview includes fixed asset system lines when fixed assets are enabled.
- Fixed asset cost mismatch blocks finalization.
- Accumulated depreciation mismatch blocks finalization.
- Net book value mismatch blocks finalization.
- Manual duplicate fixed asset control lines are blocked when system fixed asset lines exist.

Posting and lock tests:

- Post creates exactly one opening journal.
- Journal source fields use `opening_balance`.
- Batch stores `journal_entry_id`, `posted_at`, and `posted_by`.
- Second post/finalize request does not create duplicate journal.
- Lock prevents further edits.

Reopen tests:

- Reopen succeeds when no operational transactions exist after opening date.
- Reopen voids/reverses the opening journal through controlled journal rules.
- Reopen changes status to `reopened`.
- Reopen is rejected when operational transactions exist.
- Reopen is rejected when fiscal year is closed or period is locked.
- Reopen requires reason and audit log.

Setup Wizard integration tests:

- Setup finalization delegates to Opening Balance posting.
- Setup finalization locks opening balance.
- Setup finalization fails when Opening Balance validation fails.
- Setup finalization does not create duplicate opening journal on repeated request.

## Assumptions

- This plan changes only the Opening Balance implementation plan document.
- Opening Balance API prefix is `/api/opening-balance`.
- `opening_balance_batches` and `opening_balance_lines` are tenant tables because they produce tenant journal impact.
- Existing opening balance service/support classes remain the calculation and validation foundation.
- Opening fixed asset import creates register records only; Opening Balance creates the GL opening journal.
- Direct Opening Balance posting may exist for non-wizard flows, but Setup Wizard remains the primary initial setup UX.
