# Fixed Assets Implementation Plan

Date: 2026-06-15
Status: planning
Context source: `docs/accounting-setup-and-fixed-assets-context.md`

## Purpose

Implement the Fixed Assets module for tangible and intangible assets using the product and accounting decisions captured in the context document.

This plan is specific to Fixed Assets. Setup Wizard, Period-End Processing, and Opening Balance are separate implementation contexts and are referenced here only as dependencies or integration boundaries.

## Dependencies

- Read `docs/accounting-setup-and-fixed-assets-context.md` before implementing this plan.
- Use tenant migrations for all fixed asset tables.
- Keep models in `app/Models/Tenant` unless the backend modularization phase has already moved tenant models.
- Add module routes under `/api/fixed-assets` and include the route file from `routes/api.php`.
- Use existing tenant middleware and protected route pattern: `auth:sanctum`, `company.access`, and route-level `permission:*`.
- Use the existing permission catalog and permission sync flow.
- Use existing document numbering infrastructure for fixed asset numbers, capitalization documents, depreciation run documents, and disposal documents.
- Use existing account mapping infrastructure for fixed asset control accounts, accumulated depreciation/amortization, depreciation/amortization expense, clearing, and disposal gain/loss.
- Period-end depreciation/amortization posting depends on `docs/implementation_plans/period-end-processing-implementation-plan.md`.
- Opening fixed asset import depends on `docs/implementation_plans/setup-wizard-implementation-plan.md` and `docs/implementation_plans/opening-balance-implementation-plan.md`.

## MVP Scope

MVP includes:

- Fixed asset categories for tangible and intangible assets.
- Fixed asset register.
- Asset acquisition from vendor bill lines through fixed asset clearing.
- Capitalization from fixed asset clearing.
- Straight-line depreciation and amortization.
- Non-depreciable and impairment-only category handling.
- Monthly depreciation/amortization posting through period-end processing.
- Full disposal and partial disposal.
- Fixed asset reports and GL reconciliation.

MVP excludes:

- Declining balance methods.
- Double declining balance methods.
- Units-of-production method.
- Full revaluation workflow.
- Full impairment testing workflow.
- Asset maintenance scheduling.
- Barcode or QR tracking.

## Data Model

Create tenant tables for:

- `fixed_asset_categories`
- `fixed_assets`
- `fixed_asset_acquisitions`
- `fixed_asset_depreciation_schedules`
- `fixed_asset_depreciation_runs`
- `fixed_asset_depreciation_run_lines`
- `fixed_asset_disposals`
- `fixed_asset_transactions`

### `fixed_asset_categories`

Minimum fields:

- `id`
- `code`
- `name`
- `asset_class`: `tangible` or `intangible`
- `depreciation_type`: `depreciation`, `amortization`, `none`, or `impairment_only`
- `default_useful_life_years`
- `asset_account_id`
- `accumulated_depreciation_account_id`
- `depreciation_expense_account_id`
- `clearing_account_id`
- `disposal_gain_account_id`
- `disposal_loss_account_id`
- `is_active`
- timestamps

Seed default categories from the context document: land, building, vehicle, machine, office equipment, IT equipment, furniture, leasehold improvement, CIP, software, patent, copyright, goodwill, trademark, and other.

### `fixed_assets`

Minimum fields:

- `id`
- `asset_number`
- `name`
- `description`
- `fixed_asset_category_id`
- `asset_class`
- `depreciation_type`
- `status`
- `acquisition_date`
- `service_start_date`
- `first_depreciation_period`
- `last_depreciation_period`
- `useful_life_years`
- `useful_life_months`
- `quantity`
- `remaining_quantity`
- `unit_acquisition_cost`
- `acquisition_cost`
- `salvage_value`
- `depreciable_basis`
- `accumulated_depreciation`
- `net_book_value`
- `department_id`
- `project_id`
- `source_type`
- `source_id`
- `capitalized_at`
- `disposed_at`
- timestamps

Asset lifecycle statuses:

- `draft`
- `capitalized`
- `active`
- `partially_disposed`
- `disposed`
- `inactive`

Rules:

- `useful_life_years` is selected manually from controlled year presets.
- `useful_life_months` is derived from `useful_life_years * 12`.
- `first_depreciation_period` is the month after `service_start_date`.
- `quantity`, `remaining_quantity`, and `unit_acquisition_cost` support group assets and partial disposal.
- Land and CIP use `depreciation_type = none`.
- Goodwill and indefinite-life intangible assets use `depreciation_type = impairment_only`.

### `fixed_asset_acquisitions`

Minimum fields:

- `id`
- `fixed_asset_id`
- `source_type`
- `source_id`
- `source_line_id`
- `vendor_id`
- `acquisition_date`
- `quantity`
- `amount`
- `capitalized_amount`
- `journal_entry_id`
- timestamps

Use this table to connect asset register records to vendor bill lines or opening import records.

### `fixed_asset_depreciation_schedules`

Minimum fields:

- `id`
- `fixed_asset_id`
- `period_year`
- `period_month`
- `period`
- `depreciation_amount`
- `accumulated_depreciation_after`
- `net_book_value_after`
- `status`: `scheduled`, `posted`, `skipped`, or `voided`
- `journal_entry_id`
- timestamps

Schedule lines are monthly and period-based, not date-range based.

### `fixed_asset_depreciation_runs`

Minimum fields:

- `id`
- `run_number`
- `period_year`
- `period_month`
- `period`
- `status`: `draft`, `posted`, or `voided`
- `journal_entry_id`
- `posted_at`
- `posted_by`
- timestamps

Add a unique guard so one posted fixed asset depreciation/amortization run cannot be duplicated for the same company and period.

### `fixed_asset_depreciation_run_lines`

Minimum fields:

- `id`
- `fixed_asset_depreciation_run_id`
- `fixed_asset_id`
- `fixed_asset_depreciation_schedule_id`
- `depreciation_amount`
- `accumulated_depreciation_after`
- `net_book_value_after`
- timestamps

### `fixed_asset_disposals`

Minimum fields:

- `id`
- `disposal_number`
- `fixed_asset_id`
- `disposal_date`
- `disposal_type`: `sale`, `write_off`, `scrap`, or `lost`
- `disposed_quantity`
- `disposal_cost_amount`
- `disposal_accumulated_depreciation_amount`
- `disposal_net_book_value`
- `proceeds_amount`
- `gain_loss_amount`
- `cash_bank_account_id`
- `receivable_account_id`
- `journal_entry_id`
- `posted_at`
- `posted_by`
- timestamps

### `fixed_asset_transactions`

Minimum fields:

- `id`
- `fixed_asset_id`
- `transaction_type`: `acquisition`, `capitalization`, `depreciation`, `amortization`, `disposal`, `opening_import`, or `adjustment`
- `transaction_date`
- `period`
- `amount`
- `quantity`
- `source_type`
- `source_id`
- `journal_entry_id`
- `metadata`
- timestamps

Use this table as the fixed asset audit trail and report source for asset lifecycle activity.

## API And Permissions

Minimum routes:

| Method | Path | Permission | Purpose |
| --- | --- | --- | --- |
| GET | `/api/fixed-assets/categories` | `fixed_assets.settings.view` | List categories |
| POST | `/api/fixed-assets/categories` | `fixed_assets.settings.manage` | Create category |
| PATCH | `/api/fixed-assets/categories/{id}` | `fixed_assets.settings.manage` | Update category |
| GET | `/api/fixed-assets` | `fixed_assets.view` | List assets |
| POST | `/api/fixed-assets` | `fixed_assets.create` | Create draft/manual asset register |
| GET | `/api/fixed-assets/{id}` | `fixed_assets.view` | Show asset detail |
| PATCH | `/api/fixed-assets/{id}` | `fixed_assets.edit` | Update editable asset fields |
| POST | `/api/fixed-assets/{id}/capitalize` | `fixed_assets.capitalize` | Capitalize asset |
| POST | `/api/fixed-assets/{id}/dispose` | `fixed_assets.dispose` | Full or partial disposal |
| GET | `/api/fixed-assets/reports/register` | `fixed_assets.reports.view` | Fixed asset register/as-of report |
| GET | `/api/fixed-assets/reports/depreciation` | `fixed_assets.reports.view` | Depreciation detail or yearly summary |
| GET | `/api/fixed-assets/reports/disposals` | `fixed_assets.reports.view` | Disposal report |
| GET | `/api/fixed-assets/reports/reconciliation` | `fixed_assets.reports.view` | Register vs GL reconciliation |

Permission keys:

- `fixed_assets.view`
- `fixed_assets.create`
- `fixed_assets.edit`
- `fixed_assets.deactivate`
- `fixed_assets.capitalize`
- `fixed_assets.depreciate`
- `fixed_assets.dispose`
- `fixed_assets.opening_import`
- `fixed_assets.reports.view`
- `fixed_assets.settings.view`
- `fixed_assets.settings.manage`

Rules:

- Never check role names directly in controllers or services.
- Use route middleware `permission:{key}` for route access.
- Use the existing permission service for additional service-level checks where needed.
- Sensitive actions must write audit logs: capitalization, depreciation posting, disposal, opening import, and future valuation adjustments.

## Vendor Bill Acquisition Flow

Purchase bill lines must support:

- `line_classification = inventory`
- `line_classification = fixed_asset`

Do not add `expense` as a purchase bill line classification.

Rules for `fixed_asset` purchase bill lines:

- Posting debits Fixed Asset Clearing and credits Accounts Payable.
- `product_id` is optional.
- If `product_id` is selected, the product must be non-stock.
- Fixed asset lines must not create inventory stock balances.
- Fixed asset lines must not post directly to expense.
- The bill line must store `capitalized_amount`.
- `capitalized_amount` must not exceed the bill line amount.
- A bill line with capitalized amount cannot be voided without a controlled reversal workflow.

Vendor bill posting:

```text
Dr Fixed Asset Clearing
Cr Accounts Payable
```

Vendor payment remains owned by Purchase/AP and CashBank:

```text
Dr Accounts Payable
Cr Bank
```

## Capitalization Flow

Capitalization moves value from fixed asset clearing into the fixed asset register/control account.

Capitalization posting:

```text
Dr Fixed Asset
Cr Fixed Asset Clearing
```

Rules:

- Capitalization amount cannot exceed the remaining uncapitalized amount of the source vendor bill line.
- One vendor bill line may create one asset or multiple asset register records.
- If one vendor bill line quantity is split into multiple assets, total capitalization across all assets must equal or be less than the source line amount.
- Asset number is assigned at capitalization if not already assigned.
- `asset_number` must use document numbering and be unique per tenant.
- Capitalization creates a fixed asset transaction.
- Capitalization updates source bill line `capitalized_amount`.
- If `service_start_date` exists and depreciation type is `depreciation` or `amortization`, generate or refresh monthly schedule lines from `first_depreciation_period`.
- If `service_start_date` is empty for CIP or draft assets, do not generate depreciation schedules yet.

## Depreciation And Amortization Flow

MVP methods:

- `straight_line`
- `none`
- `impairment_only`

User-facing type behavior:

- Tangible assets with periodic expense use depreciation.
- Finite-life intangible assets use amortization.
- Land and CIP do not depreciate.
- Goodwill and indefinite-life intangible assets are impairment-only in MVP and do not generate periodic schedules.

Rules:

- Depreciation/amortization is monthly and period-based.
- Run input uses `period_year` and `period_month`.
- The first depreciation period is always the month after `service_start_date`.
- No daily proration in MVP.
- If `service_start_date = 2026-01-01`, first depreciation period is `2026-02`.
- If `service_start_date = 2026-01-20`, first depreciation period is `2026-02`.
- The same period must not create duplicate posted depreciation/amortization journals.
- Posted depreciation schedules must not be rewritten by later partial disposal or asset edits.
- Depreciation/amortization posting is triggered through the Period-End Processing dependency, not by a free-date Fixed Assets endpoint.

Straight-line calculation:

```text
depreciable_basis = acquisition_cost - salvage_value
monthly_depreciation = depreciable_basis / useful_life_months
```

After partial disposal, future depreciation uses the remaining depreciable basis and remaining schedule periods.

Period-end posting:

```text
Dr Depreciation Expense or Amortization Expense
Cr Accumulated Depreciation or Accumulated Amortization
```

## Disposal Flow

Support both full and partial disposal.

Disposal types:

- `sale`
- `write_off`
- `scrap`
- `lost`

Partial disposal is allowed only when:

- The asset has `quantity > 1` or is explicitly treated as a quantity-tracked group asset.
- `disposed_quantity > 0`.
- `disposed_quantity <= remaining_quantity`.

Proportional disposal rule:

```text
disposal_ratio = disposed_quantity / remaining_quantity_before_disposal
disposal_cost_amount = current_cost_basis * disposal_ratio
disposal_accumulated_depreciation_amount = current_accumulated_depreciation * disposal_ratio
disposal_net_book_value = disposal_cost_amount - disposal_accumulated_depreciation_amount
gain_loss_amount = proceeds_amount - disposal_net_book_value
```

Disposal posting:

```text
Dr Cash/Bank or Receivable
Dr Accumulated Depreciation, disposed portion
Cr Fixed Asset, disposed cost portion
Dr/Cr Gain or Loss on Disposal
```

Rules:

- Full disposal sets `remaining_quantity = 0` and status `disposed`.
- Partial disposal reduces remaining quantity, cost basis, accumulated depreciation, and net book value proportionally.
- Partial disposal keeps the asset active or `partially_disposed` while `remaining_quantity > 0`.
- Future depreciation after partial disposal uses the remaining depreciable basis.
- Already posted depreciation must not be recalculated.
- If depreciation for the disposal period has already been posted, disposal is blocked unless a controlled reversal/repost workflow exists.

## Reports

Fixed asset value and depreciation reports use monthly periods, not arbitrary date ranges.

### Register / As-Of Report

Endpoint:

```text
GET /api/fixed-assets/reports/register?as_of_period=YYYY-MM
```

Required parameter:

- `as_of_period`

Core columns:

- `asset_number`
- `asset_name`
- `category`
- `asset_class`
- `acquisition_date`
- `service_start_date`
- `useful_life_years`
- `acquisition_cost`
- `depreciation_period_total`
- `depreciation_current_year`
- `accumulated_depreciation_until_period`
- `net_book_value_as_of_period`
- `quantity`
- `remaining_quantity`
- `status`
- `department`
- `project`

### Depreciation Report

Endpoint:

```text
GET /api/fixed-assets/reports/depreciation?period_from=YYYY-MM&period_to=YYYY-MM&mode=detail
```

Parameters:

- `period_to` is required.
- `period_from` is optional and defaults to January of the `period_to` year.
- `mode` is `detail` or `yearly_summary`.

Detail columns:

- `period`
- `asset_number`
- `asset_name`
- `category`
- `depreciation_amount`
- `accumulated_depreciation_after`
- `net_book_value_after`
- `journal_entry_number`
- `status`

Yearly summary columns:

- `year`
- `asset_number`
- `asset_name`
- `depreciation_year_total`
- `accumulated_depreciation_end_of_year`
- `net_book_value_end_of_year`

### Disposal Report

Endpoint:

```text
GET /api/fixed-assets/reports/disposals?disposal_date_from=YYYY-MM-DD&disposal_date_to=YYYY-MM-DD
```

Disposal reports may use transaction date ranges because disposal is event-based.

### Reconciliation Report

Endpoint:

```text
GET /api/fixed-assets/reports/reconciliation?as_of_period=YYYY-MM
```

Compare:

```text
asset register cost total vs GL fixed asset control account balance
asset register accumulated depreciation vs GL accumulated depreciation account balance
asset register net book value vs GL net book value
```

## Accounting Rules

Required account mappings:

- `fixed_assets.clearing`
- `fixed_assets.cost`
- `fixed_assets.accumulated_depreciation`
- `fixed_assets.depreciation_expense`
- `fixed_assets.accumulated_amortization`
- `fixed_assets.amortization_expense`
- `fixed_assets.disposal_gain`
- `fixed_assets.disposal_loss`

Journal rules:

- Vendor bill acquisition posts to clearing, not directly to fixed asset cost.
- Capitalization posts from clearing to fixed asset cost.
- Depreciation and amortization are posted only by period-end processing.
- Disposal removes the disposed cost and accumulated depreciation/amortization portion.
- Journal entries must follow existing immutability, void, and reversal rules.
- Any posted journal must keep source document links back to the fixed asset transaction or source bill line.

## Guardrails

- Fixed assets are not inventory and must not create stock balances.
- Purchase invoices must not introduce expense lines for this product direction.
- `service_start_date` is required for active depreciable/amortizable assets.
- `service_start_date` must be greater than or equal to `acquisition_date`, except for explicitly supported opening/import legacy assets.
- Do not expose manual `depreciation_start_date`.
- Do not allow duplicate capitalization from the same source line beyond the source amount.
- Do not allow duplicate depreciation/amortization posting for the same period.
- Do not allow asset edits that would rewrite already posted depreciation.
- Do not allow disposal before capitalization.
- Do not allow partial disposal quantity greater than remaining quantity.
- Do not allow voiding a capitalized vendor bill line unless a controlled reversal workflow handles the asset and journal impact.
- Opening fixed asset import must create register records only and must not create a standalone GL journal.

## Test Plan

Permission tests:

- Each route enforces its mapped `fixed_assets.*` permission.
- Users without permission cannot access sensitive actions.
- Role names are never used as access checks.

Migration and model tests:

- Tenant migrations create all fixed asset tables.
- Default categories can be seeded.
- Asset lifecycle status transitions persist correctly.

Vendor bill tests:

- `fixed_asset` line posts debit Fixed Asset Clearing and credit Accounts Payable.
- `inventory` line keeps existing inventory behavior.
- `expense` line classification is not accepted on purchase bills.
- `product_id` on fixed asset lines must be non-stock.
- Capitalized bill lines cannot be voided without controlled reversal.

Capitalization tests:

- Capitalization cannot exceed remaining source bill line amount.
- Capitalization creates journal Dr Fixed Asset / Cr Fixed Asset Clearing.
- Capitalization updates source line `capitalized_amount`.
- Duplicate capitalization beyond source amount is rejected.
- One bill line can create multiple asset records without exceeding source amount.

Depreciation and amortization tests:

- `service_start_date = 2026-01-01` starts depreciation in `2026-02`.
- `service_start_date = 2026-01-20` starts depreciation in `2026-02`.
- Same period run cannot create duplicate journals.
- Land and other non-depreciable assets do not generate schedules.
- Software uses amortization label and amortization account mapping.
- Posted schedules are not rewritten by later edits.

Disposal tests:

- Full disposal sets remaining quantity to zero and status to `disposed`.
- Partial disposal reduces quantity, cost basis, accumulated depreciation, and net book value proportionally.
- Gain or loss equals proceeds minus disposed net book value.
- Disposal is blocked if depreciation for the disposal period was already posted and no reversal/repost workflow exists.

Report tests:

- Register report requires `as_of_period`.
- Depreciation report accepts monthly `period_from` and `period_to`.
- If `period_from` is omitted, it defaults to January of `period_to` year.
- Current-year depreciation and accumulated depreciation are correct.
- Reconciliation report matches GL after posting acquisition, capitalization, depreciation, and disposal transactions.

## Deferred / Pre-Production

Deferred after MVP:

- Declining balance depreciation.
- Double declining balance depreciation.
- Units-of-production depreciation.
- Asset maintenance scheduling.
- Barcode or QR tracking.

Pre-production requirement:

- Implement valuation adjustment workflow covering both revaluation and impairment before the overall application is considered production ready.
- Revaluation and impairment should be implemented together because both affect carrying amount, future depreciation basis, audit trail, and reports.

## Assumptions

- This plan changes only the Fixed Assets implementation plan document.
- Context decisions remain in `docs/accounting-setup-and-fixed-assets-context.md`.
- Period-end API details remain in `docs/implementation_plans/period-end-processing-implementation-plan.md`.
- Setup wizard details remain in `docs/implementation_plans/setup-wizard-implementation-plan.md`.
- Opening balance details remain in `docs/implementation_plans/opening-balance-implementation-plan.md`.
- Fixed asset opening import is coordinated by setup wizard and opening balance finalization to avoid double posting.
