# Accounting Setup And Fixed Assets Context

Date: 2026-06-15
Status: planning
Scope: Backend context and product/accounting decisions for accounting setup wizard, fixed assets, intangible assets, opening fixed asset import, and related period-end behavior.

## Document Set Note

This document is intentionally broader than fixed assets only. It captures decisions that affect both the Fixed Assets module and the initial company accounting setup flow.

Implementation plans are split by context in separate files, similar to the frontend docs structure. Plan files live under `docs/implementation_plans/`.

Current/future plan files:

- `docs/implementation_plans/fixed-assets-implementation-plan.md`
- `docs/implementation_plans/setup-wizard-implementation-plan.md`
- `docs/implementation_plans/period-end-processing-implementation-plan.md`
- `docs/implementation_plans/opening-balance-implementation-plan.md`

## Goal

Add a tenant module for fixed assets and intangible assets. The module should manage asset categories, asset register, capitalization, depreciation or amortization, disposal, and fixed asset reports.

The module should not be implemented as inventory or product data. Fixed assets have their own lifecycle and accounting treatment.

Also add an initial accounting setup wizard that can orchestrate setup steps across company settings, module settings, chart of accounts, account mappings, opening balance, and opening fixed asset import.

## Naming

User-facing module name:

- Aktiva Tetap
- Optionally later: Aktiva Tetap & Aset Tidak Berwujud

Backend module name:

- `FixedAssets`

API prefix:

- `/api/fixed-assets`

## Context

This section captures the discussion decisions before implementation starts.

Context groups captured so far:

- Fixed Assets module context.
- Purchase invoice/vendor bill context for fixed asset acquisition.
- Period-end depreciation context.
- Initial setup wizard context.
- Permission/access context.

Implementation plans for each context group are kept in separate files under `docs/implementation_plans/`.

### Category Context

The module should cover both physical fixed assets and intangible assets. Categories should be selected from predefined options instead of being treated as free-form text.

Initial category coverage:

- Tanah
- Bangunan
- Kendaraan
- Mesin dan Peralatan Produksi
- Peralatan Kantor
- Komputer dan Perangkat IT
- Furniture dan Fixture
- Renovasi / Leasehold Improvement
- Aset Dalam Penyelesaian
- Software
- Goodwill
- Hak Paten
- Copyright / Hak Cipta
- Merek Dagang / Trademark
- Aset Lainnya

Category decisions:

- Land is not depreciated.
- Construction in progress is not depreciated until it becomes an active asset.
- Software, patents, copyright, and finite-life trademarks are intangible assets and use amortization.
- Goodwill and indefinite-life trademarks should use `impairment_only` for MVP.
- Categories should not force useful life. Users choose useful life manually on the asset form.
- Each category owns classification, depreciation/amortization type defaults, residual policy defaults, and account defaults.

### Useful Life Context

Decision: useful life is selected manually by the user on the asset form. It is not automatically determined from asset category.

Example: a laptop may normally use a shorter life, but if the user chooses 8 years, the system should allow it.

Do not use a free numeric input and do not show month-by-month choices. Use a controlled select with year-based options.

Recommended useful life choices:

- 4 years
- 8 years
- 16 years
- 20 years

Additional choices for building-like assets if needed:

- 10 years for non-permanent building treatment
- 20 years for permanent building treatment

Tax reference note:

- Indonesian tax grouping commonly uses non-building asset groups of 4, 8, 16, and 20 years.
- Building treatment commonly separates non-permanent and permanent buildings.
- These presets are used as controlled choices, not as an automatic tax engine.

Storage:

- User-facing form stores/receives useful life in years.
- Backend may store `useful_life_years` and/or derived `useful_life_months` for calculation.
- If `useful_life_months` is stored, it is derived from `useful_life_years * 12`, not selected directly by the user.

Asset form behavior:

1. User selects asset category.
2. User selects useful life manually from preset year choices.
3. System calculates depreciation/amortization schedule from selected useful life.
4. Category may suggest a default, but it must remain user-editable before capitalization.

Custom free-form useful life:

- Not part of MVP.
- Can be reconsidered later only if there is a strong accounting requirement.

### Depreciation And Amortization Context

MVP should only expose:

- Straight line
- Non-depreciable / non-amortizable
- Impairment only

Other methods can be added after MVP:

- Declining balance
- Double declining balance
- Units of production

Finite-life intangible assets can use the same calculation engine as straight-line depreciation, but the user-facing label and accounting mapping should be amortization.

### Open Topics Not Yet Discussed

These topics still need product/accounting decisions before implementation:

- Opening fixed asset import for companies that already have existing assets.

### Purchase Invoice / Vendor Bill Context

Decision: fixed asset acquisition through vendor bill is part of MVP, not a later phase. Vendor bill means faktur pembelian / tagihan vendor.

All fixed asset acquisitions from purchase invoices should go through fixed asset clearing. This keeps AP subsidiary ledger in Purchase/AP while the asset register remains owned by Fixed Assets.

Vendor bill posting:

```text
Dr Fixed Asset Clearing
Cr Accounts Payable
```

Asset capitalization:

```text
Dr Fixed Asset
Cr Fixed Asset Clearing
```

Vendor payment:

```text
Dr Accounts Payable
Cr Bank
```

This decision supports credit purchases of fixed assets. The vendor bill creates the payable and appears in the AP subsidiary ledger. Capitalization only moves the amount from clearing into the asset account.

Fixed asset purchases must not create stock balances and must not be treated as inventory. A purchase invoice can use the same purchase line structure, but the line must be classified separately from inventory.

Recommended purchase line classifications:

- `inventory`
- `fixed_asset`

Do not add `expense` as a purchase invoice line classification for this product direction. Beban/biaya outside AP and AR should be recorded through CashBank:

- `cash-bank/cash-payments` for other payments such as utilities, rent, internet, admin fees, and miscellaneous expenses.
- `cash-bank/cash-receipts` for other receipts such as non-invoice income, reimbursements, or miscellaneous receipts.

Cash payment and cash receipt lines already post directly to selected COA lines and support department/project dimensions.

For `fixed_asset` purchase lines:

- `product_id` is optional.
- If `product_id` is selected, the product must be non-stock: `is_stock_item = false`.
- The line should carry `fixed_asset_category_id` or metadata needed to create the fixed asset register.
- Posting debits `fixed_assets.clearing`, not expense and not inventory.
- The line must track `capitalized_amount` to prevent double capitalization.
- Capitalized amount cannot exceed the bill line amount.

UI/catalog decision:

- Do not put fixed assets into inventory stock lists.
- Purchase invoices may use description-only fixed asset lines.
- Purchase invoices may also use non-stock catalog items for repeatable asset purchases.
- If the existing product catalog is reused, label it as item/service catalog in purchase forms, not as inventory.
- Fixed asset catalog items should use `product_type = fixed_asset` or equivalent and `is_stock_item = false`.
- The actual fixed asset record is created in the Fixed Assets module, not in inventory.

Example:

```text
Vendor Bill line:
Description: Laptop Lenovo
Line classification: fixed_asset
Quantity: 5
Amount: 60,000,000
Posting: Dr Fixed Asset Clearing / Cr AP

Fixed Assets capitalization:
Create 5 asset registers x 12,000,000
Posting: Dr Fixed Asset - IT / Cr Fixed Asset Clearing
```

Void/reversal rule:

- A vendor bill line that has already been capitalized cannot be voided casually.
- If no depreciation or amortization has been posted, uncapitalization/reversal may be allowed.
- If depreciation or amortization has already been posted, voiding the vendor bill must be blocked or handled through a controlled reversal workflow.

### Period-End Depreciation Context

Decision: depreciation and amortization are posted from an Akhir Periode menu. The user selects a month period, not an arbitrary date.

Current backend note:

- The backend already has fiscal year closing and accounting period lock APIs.
- The backend does not yet have a dedicated monthly Akhir Periode / period-end processing API that runs routines such as fixed asset depreciation/amortization.
- This period-end menu/API must be added as part of the fixed asset implementation or as an Accounting module dependency before depreciation posting can be user-facing.

Period-end processing should automatically create journals for eligible period-end routines such as fixed asset depreciation/amortization.

Depreciation proration policy:

- Use Option B: start depreciation in the next monthly period after the asset is ready for use.
- No daily proration in MVP.
- The depreciation run is monthly and period-based.

Example:

```text
service_start_date: 2026-01-20
first_depreciation_period: 2026-02
```

If the asset is ready on the first day of the month, still use the same Option B policy unless a later product decision creates an exception.

```text
service_start_date: 2026-01-01
first_depreciation_period: 2026-02
```

Recommended field names:

- `first_depreciation_period`
- `last_depreciation_period`
- `depreciation_period_year`
- `depreciation_period_month`

Recommended depreciation run input:

```text
period_year
period_month
```

Do not use a free date picker for depreciation runs.

Recommended Accounting module endpoints:

- `GET /accounting/period-end/status?period=YYYY-MM`
- `GET /accounting/period-end/checklist?period=YYYY-MM`
- `POST /accounting/period-end/run`
- `POST /accounting/period-end/reopen`

Recommended period-end routines for MVP:

- Fixed asset depreciation/amortization

Future routines can include:

- Inventory valuation checks
- Bank/account reconciliation checks
- Accruals and deferrals
- Tax settlement

### Acquisition And Service Date Context

Decision: the asset form must include two date inputs:

- `acquisition_date`: tanggal pembelian / tanggal perolehan.
- `service_start_date`: tanggal mulai digunakan / tanggal aset siap digunakan.

Do not expose `depreciation_start_date` as a manual user input. It must not contradict the Option B proration policy.

The system derives depreciation period fields from `service_start_date`:

```text
first_depreciation_period = month after service_start_date
depreciation_start_date = first day of first_depreciation_period, as a derived snapshot if needed
```

Example:

```text
acquisition_date: 2026-01-05
service_start_date: 2026-01-20
first_depreciation_period: 2026-02
depreciation_start_date: 2026-02-01
```

Validation rules:

- `acquisition_date` is required.
- `service_start_date` is required for depreciable/amortizable active assets.
- `service_start_date` must be greater than or equal to `acquisition_date`, except for opening/imported legacy assets if that workflow explicitly allows exceptions.
- Non-depreciable assets, such as land, may still store `service_start_date` for operational reference but should not generate depreciation schedules.
- Construction in progress may leave `service_start_date` empty until the asset is ready for use.

UI behavior:

- Show Tanggal Pembelian.
- Show Tanggal Mulai Digunakan.
- Show Periode Mulai Susut as read-only, calculated from `service_start_date`.
- Do not show a manual Tanggal Mulai Penyusutan field.

### Partial Disposal Context

Decision: partial disposal must be supported.

Reason: some assets may be recorded as a group asset with quantity, then sold or written off partially. Example: gas cylinders initially recorded as fixed assets, then some cylinders are sold later.

The module should support both:

- Full disposal: the whole asset is sold, written off, scrapped, or lost.
- Partial disposal: only part of the asset quantity/value is disposed.

Recommended asset quantity fields:

- `quantity`
- `remaining_quantity`
- `unit_acquisition_cost`

Recommended disposal fields:

- `disposed_quantity`
- `disposal_cost_amount`
- `disposal_accumulated_depreciation_amount`
- `disposal_net_book_value`
- `proceeds_amount`
- `gain_loss_amount`

Partial disposal should be allowed only when:

- The asset has quantity greater than 1, or is explicitly marked as a group/quantity-tracked asset.
- `disposed_quantity` is greater than 0.
- `disposed_quantity` is less than or equal to `remaining_quantity`.

Proportional disposal rule:

```text
disposal_ratio = disposed_quantity / remaining_quantity_before_disposal
disposal_cost_amount = current_cost_basis * disposal_ratio
disposal_accumulated_depreciation_amount = current_accumulated_depreciation * disposal_ratio
disposal_net_book_value = disposal_cost_amount - disposal_accumulated_depreciation_amount
gain_loss_amount = proceeds_amount - disposal_net_book_value
```

Partial disposal journal:

```text
Dr Cash/Bank or Receivable
Dr Accumulated Depreciation, disposed portion
Cr Fixed Asset, disposed cost portion
Dr/Cr Gain or Loss on Disposal
```

After partial disposal:

- Reduce `remaining_quantity`.
- Reduce asset cost basis by `disposal_cost_amount`.
- Reduce accumulated depreciation by `disposal_accumulated_depreciation_amount`.
- Recalculate net book value.
- Keep asset status `active` if `remaining_quantity > 0`.
- Set asset status `disposed` only when `remaining_quantity = 0`.

Depreciation after partial disposal:

- Future depreciation should use the remaining depreciable basis.
- Already posted depreciation must not be rewritten.
- If period-end depreciation has already been posted for the disposal period, disposal must either be blocked or require a controlled reversal/repost workflow.

### Revaluation And Impairment Context

Decision:

- Revaluation is not part of fixed asset MVP.
- Full impairment workflow is not part of fixed asset MVP.
- Revaluation and impairment must be implemented together in one valuation adjustment phase.
- This avoids a later refactor because both workflows adjust asset carrying amount, future depreciation basis, audit trail, and reports.
- The valuation adjustment phase is required before the application is considered production ready.

MVP behavior:

- Keep `impairment_only` classification for goodwill and indefinite-life intangible assets.
- Handle assets with no remaining value through disposal/write-off.
- Do not expose fair value revaluation screens or journals in MVP.
- Do not expose a full impairment testing workflow in MVP.

Future pre-production valuation adjustment scope:

- Fixed asset revaluation records.
- Fixed asset impairment records.
- Fair value and appraisal date fields.
- Appraisal/reference document metadata.
- Revaluation surplus account mapping.
- Revaluation decrease/loss handling.
- Recoverable amount and impairment loss handling.
- Accumulated impairment or direct asset reduction policy.
- Impairment reversal policy if allowed.
- Updated depreciation basis after revaluation.
- Updated depreciation basis after impairment.
- Shared reports and audit trail for valuation changes.

Recommended future tables:

- `fixed_asset_revaluations`
- `fixed_asset_impairments`

Recommended future account mappings:

- `fixed_assets.revaluation_surplus`
- `fixed_assets.revaluation_loss`
- `fixed_assets.accumulated_impairment`
- `fixed_assets.impairment_loss`

Production readiness rule:

- Fixed Assets can ship MVP internally without revaluation/impairment workflow.
- The overall application should not be marked production ready until the valuation adjustment phase, covering both revaluation and impairment, has been implemented and verified.

### Accounting Versus Fiscal Depreciation Context

Decision: MVP uses one accounting depreciation/amortization schedule only.

The useful life select is tax-aware through controlled presets, but the system does not generate a separate fiscal depreciation schedule in MVP.

MVP behavior:

- User selects `useful_life_years` manually from controlled presets such as 4, 8, 16, and 20 years.
- The selected useful life drives the accounting depreciation/amortization schedule.
- Period-end posting creates accounting journals only.
- No separate `fiscal_useful_life_years`.
- No fiscal depreciation schedule.
- No fiscal reconciliation report.

Future tax/fiscal scope:

- Fiscal useful life.
- Fiscal depreciation/amortization schedules.
- Fiscal asset group references.
- Tax depreciation report.
- Fiscal reconciliation report.

This keeps MVP simple while preserving a clean path for a future Tax module.

### Initial Setup Wizard And Opening Fixed Asset Import Context

Decision: opening fixed asset import should be part of the initial company setup wizard, but it must not post its own standalone GL journal.

Current backend note:

- There is no dedicated onboarding/setup wizard API yet.
- Company provisioning exists as backend service/command.
- Company/module settings exist.
- Opening balance service exists, but there is no user-facing opening balance API route/controller yet.

Wizard design principle:

- The wizard is an orchestrator/checklist.
- Fixed Assets owns asset register details.
- Opening Balance owns the final GL opening journal.
- The wizard coordinates both so the user experience is unified without double posting.

Frontend/backend state principle:

- Frontend may use `sessionStorage` to keep in-progress form input and current step state during refresh.
- Backend should not duplicate every transient form field just to survive refresh.
- Backend remains the authoritative source for setup status, validation, preview, finalization, locks, and audit trail.
- Backend must prevent double finalization and inconsistent cross-module state even if frontend session data is stale.

Recommended wizard flow:

1. Company profile and cutover/opening date.
2. Module selection, including `fixed_asset_enabled`.
3. Chart of accounts and account mappings.
4. Opening fixed asset import, shown only when fixed assets are enabled.
5. Opening balance preview.
6. Reconciliation checks.
7. Finalize setup.

Opening fixed asset import behavior:

- Creates opening fixed asset register records.
- Stores cost, accumulated depreciation/amortization, net book value, remaining quantity, and useful life data as of cutover.
- Does not automatically create a GL journal.
- Produces totals that feed the opening balance preview.

Opening balance finalization behavior:

- Creates one opening balance journal for the company.
- Includes fixed asset totals from the opening fixed asset register.
- Prevents duplicate manual entry for fixed asset control accounts unless explicitly overridden with validation.

Recommended reconciliation checks:

```text
sum(opening fixed asset cost) == opening balance fixed asset control account debit
sum(opening accumulated depreciation) == opening balance accumulated depreciation credit
sum(opening net book value) == cost - accumulated depreciation
```

If totals do not match, setup cannot be finalized unless a privileged override is explicitly supported later.

Detailed wizard design is deferred until after fixed asset module rules, including permission rules, are finalized.

### Fixed Asset Reports Context

Decision: fixed asset value and depreciation reports use monthly accounting periods, not arbitrary date ranges.

Reason:

- Depreciation/amortization is posted from the monthly Akhir Periode process.
- Report parameters must align with the same monthly period model.
- This avoids confusing partial-date depreciation calculations.

Period-based report parameters:

- `as_of_period`, format `YYYY-MM`
- `period_from`, format `YYYY-MM`
- `period_to`, format `YYYY-MM`

Recommended behavior:

- For as-of reports, require `as_of_period`.
- For range reports, require `period_to`.
- If `period_from` is omitted, default it to January of `period_to` year.

Example:

```text
period_from = 2026-01
period_to = 2026-03
```

The report should show depreciation from January 2026 through March 2026, accumulated depreciation through March 2026, and net book value as of March 2026.

Core report columns for fixed asset register/as-of report:

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

Depreciation report modes:

- Period detail: monthly lines between `period_from` and `period_to`.
- Yearly summary: total depreciation per year.

Period detail columns:

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

Transactional reports may use date ranges:

- Acquisition report: `acquisition_date_from`, `acquisition_date_to`.
- Disposal report: `disposal_date_from`, `disposal_date_to`.
- Asset audit trail: `transaction_date_from`, `transaction_date_to`.

MVP reports:

- Fixed Asset Register / as-of report.
- Depreciation report with period detail and yearly summary.
- Disposal report.
- Fixed asset reconciliation report against GL.

MVP reconciliation report should compare:

```text
asset register cost total vs GL fixed asset control account balance
asset register accumulated depreciation vs GL accumulated depreciation account balance
asset register net book value vs GL net book value
```

### Permission And Access Rule Context

Decision: fixed asset access must follow the existing flexible permission system. Do not hardcode access by user type such as owner, admin, finance, or staff.

Current backend permission model:

- Permission keys are stored in the permission catalog.
- System roles and company custom roles can contain permission keys.
- Individual company users can have allow/deny permission overrides.
- Effective permissions are role permissions plus user allow overrides minus user deny overrides.
- Route middleware uses `permission:{permission_key}`.

Product rule:

- A user such as "Wardi" can be given full fixed asset access by checking all fixed asset permission keys.
- The same user can be limited by unchecking or denying selected permission keys.
- Role names are templates only; actual access must come from checked permissions.

Recommended fixed asset permission keys:

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

Recommended valuation adjustment permission keys for the later pre-production phase:

- `fixed_assets.revaluation.view`
- `fixed_assets.revaluation.create`
- `fixed_assets.revaluation.post`
- `fixed_assets.impairment.view`
- `fixed_assets.impairment.create`
- `fixed_assets.impairment.post`

Recommended route mapping:

- List/show asset and category data: `fixed_assets.view`
- Create asset register: `fixed_assets.create`
- Edit draft or allowed fields: `fixed_assets.edit`
- Activate/deactivate category or asset: `fixed_assets.deactivate`
- Capitalize asset: `fixed_assets.capitalize`
- Run period-end fixed asset depreciation/amortization: `fixed_assets.depreciate`
- Dispose/write off asset, including partial disposal: `fixed_assets.dispose`
- Opening fixed asset import in setup wizard: `fixed_assets.opening_import`
- Reports: `fixed_assets.reports.view`
- View fixed asset settings/categories/account mappings: `fixed_assets.settings.view`
- Manage fixed asset settings/categories/account mappings: `fixed_assets.settings.manage`

Permission catalog requirements:

- Add all fixed asset permission keys to `config/permissions.php`.
- Sync them into the `permissions` table.
- They must appear in the access matrix/catalog so administrators can check/uncheck them for roles or individual users.
- Use normal matrix columns where possible: daftar, tambah, ubah, hapus, laporan, persetujuan.
- Special actions such as capitalize, depreciate, opening_import, and settings.manage may appear as special permissions if they do not map cleanly to matrix columns.

Access guardrails:

- Never check role names directly in Fixed Assets controllers/services.
- Always use route-level `permission:*` middleware or `PermissionService::can()`.
- Sensitive actions should also write audit logs: capitalization, depreciation run post/void, disposal, opening import, future revaluation, future impairment.

## Asset Classification

Use `asset_class` to separate tangible and intangible assets:

- `tangible`
- `intangible`

Use `depreciation_type` to control how periodic expense is handled:

- `depreciation`
- `amortization`
- `none`
- `impairment_only`

Examples:

| Asset | asset_class | depreciation_type |
| --- | --- | --- |
| Vehicle | tangible | depreciation |
| Building | tangible | depreciation |
| Machine | tangible | depreciation |
| Land | tangible | none |
| Construction in progress | tangible | none |
| Software | intangible | amortization |
| Patent | intangible | amortization |
| Copyright | intangible | amortization |
| Goodwill | intangible | impairment_only |
| Trademark with indefinite life | intangible | impairment_only |

## Default Asset Categories

Seed default categories for new tenant databases:

| Code | Name | Class | Depreciation type | Suggested useful life choices |
| --- | --- | --- | --- | --- |
| LAND | Tanah | tangible | none | null |
| BUILDING | Bangunan | tangible | depreciation | 10 or 20 years |
| VEHICLE | Kendaraan | tangible | depreciation | 4, 8, 16, or 20 years |
| MACHINE | Mesin dan Peralatan Produksi | tangible | depreciation | 4, 8, 16, or 20 years |
| OFFICE_EQUIP | Peralatan Kantor | tangible | depreciation | 4, 8, 16, or 20 years |
| IT_EQUIP | Komputer dan Perangkat IT | tangible | depreciation | 4, 8, 16, or 20 years |
| FURNITURE | Furniture dan Fixture | tangible | depreciation | 4, 8, 16, or 20 years |
| LEASEHOLD | Renovasi / Leasehold Improvement | tangible | depreciation | 4, 8, 16, or 20 years |
| CIP | Aset Dalam Penyelesaian | tangible | none | null |
| SOFTWARE | Software | intangible | amortization | 4, 8, 16, or 20 years |
| PATENT | Hak Paten | intangible | amortization | 4, 8, 16, or 20 years |
| COPYRIGHT | Copyright / Hak Cipta | intangible | amortization | 4, 8, 16, or 20 years |
| GOODWILL | Goodwill | intangible | impairment_only | null |
| TRADEMARK | Merek Dagang / Trademark | intangible | amortization or impairment_only | 4, 8, 16, or 20 years if finite-life |
| OTHER | Aset Lainnya | tangible | depreciation | 4, 8, 16, or 20 years |

Important rules:

- Land must not be depreciated.
- Construction in progress must not be depreciated until transferred to an active asset.
- Goodwill should not use routine amortization in MVP; use `impairment_only`.
- Intangible finite-life assets use amortization, not depreciation, even if the calculation engine is shared.

## Useful Life UX And Storage

Do not expose useful life as a free numeric input by default.

Do not expose monthly increments. Users choose a year-based useful life preset.

Recommended user-facing options:

- 4 years
- 8 years
- 16 years
- 20 years

Optional building-specific options:

- 10 years
- 20 years

Store useful life as:

- `useful_life_years`
- `useful_life_months`, derived from years if needed for monthly schedule generation

Category fields:

- `suggested_useful_life_years`, nullable and editable on asset form
- `default_depreciation_method`
- `default_residual_policy`

Asset form behavior:

1. User selects asset category.
2. User selects useful life manually from year-based choices.
3. System fills depreciation or amortization method, residual policy, and account mappings from category defaults when available.
4. User may change useful life before capitalization.

Custom free-form useful life fields:

- `useful_life_override_reason`

Custom free-form useful life is not part of MVP.

## Asset Age Concepts

Keep these concepts separate:

- `acquisition_date`: date the asset was purchased or obtained.
- `service_start_date`: date the asset was ready for use.
- `depreciation_start_date`: derived snapshot date, first day of `first_depreciation_period`, not a manual user input.
- `first_depreciation_period`: first monthly period eligible for depreciation/amortization under Option B.
- `useful_life_years`: user-selected accounting useful life.
- `useful_life_months`: derived internal value for monthly schedule generation.
- `depreciation_end_date`: derived or stored snapshot from start date and useful life.

Calculated response fields can include:

- `asset_age_months`
- `depreciated_months`
- `remaining_life_months`
- `net_book_value`

## Depreciation And Amortization Methods

Supported method values:

- `straight_line`
- `declining_balance`
- `double_declining_balance`
- `units_of_production`
- `non_depreciable`
- `impairment_only`

Implementation phases:

Phase 1:

- `straight_line`
- `non_depreciable`
- `impairment_only`

Phase 2:

- `declining_balance`
- `double_declining_balance`

Phase 3:

- `units_of_production`

For MVP, expose only:

- Garis Lurus
- Tanpa Penyusutan / Tanpa Amortisasi
- Impairment Only

Straight-line formula:

```text
(acquisition_cost - residual_value) / useful_life_months
```

For intangible finite-life assets, use the same straight-line formula but label the periodic expense as amortization.

## Proposed Tenant Tables

### purchase document line additions

Purpose: allow purchase invoices to classify fixed asset purchases without treating them as inventory.

Recommended additions to vendor bill lines, and optionally upstream purchase order/goods receipt lines if the full purchase flow should support asset lines:

- `line_class` with values `inventory`, `fixed_asset`
- `fixed_asset_category_id`
- `fixed_asset_id` nullable, if a line creates exactly one asset
- `capitalized_amount`
- `capitalized_quantity`
- `capitalized_at`
- `metadata`

For MVP, the critical table is `vendor_bill_lines` because AP is recognized from the vendor bill. Purchase order and goods receipt integration can be added in the same implementation only if needed for source document continuity.

### fixed_asset_categories

Purpose: category defaults and account mappings.

Recommended fields:

- `id`
- `code`
- `name`
- `asset_class`
- `depreciation_type`
- `is_depreciable`
- `suggested_useful_life_years`
- `default_depreciation_method`
- `default_residual_policy`
- `asset_account_id`
- `accumulated_depreciation_account_id`
- `depreciation_expense_account_id`
- `gain_loss_account_id`
- `is_active`
- `metadata`
- timestamps

### fixed_assets

Purpose: asset register.

Recommended fields:

- `id`
- `asset_number`
- `asset_name`
- `fixed_asset_category_id`
- `asset_class`
- `depreciation_type`
- `status`
- `acquisition_date`
- `service_start_date`
- `depreciation_start_date`
- `depreciation_end_date`
- `acquisition_cost`
- `residual_value`
- `quantity`
- `remaining_quantity`
- `unit_acquisition_cost`
- `useful_life_years`
- `useful_life_months`
- `useful_life_override_reason`
- `depreciation_method`
- `accumulated_depreciation_amount`
- `net_book_value`
- `location`
- `department_id`
- `project_id`
- `vendor_id`
- `source_type`
- `source_id`
- `source_number`
- `capitalization_journal_entry_id`
- `created_by`
- `updated_by`
- `capitalized_by`
- `capitalized_at`
- `disposed_by`
- `disposed_at`
- `metadata`
- timestamps

Recommended statuses:

- `draft`
- `active`
- `fully_depreciated`
- `disposed`
- `written_off`
- `inactive`

### fixed_asset_depreciation_schedules

Purpose: generated periodic schedule per asset.

Recommended fields:

- `id`
- `fixed_asset_id`
- `period_year`
- `period_month`
- `period_start_date`
- `period_end_date`
- `depreciation_amount`
- `accumulated_depreciation_after`
- `net_book_value_after`
- `status`
- `journal_entry_id`
- `posted_at`
- `metadata`
- timestamps

Recommended statuses:

- `pending`
- `posted`
- `void`

### fixed_asset_depreciation_runs

Purpose: batch posting by period.

Recommended fields:

- `id`
- `run_number`
- `period_year`
- `period_month`
- `run_date`
- `status`
- `total_assets`
- `total_amount`
- `posted_by`
- `posted_at`
- `voided_by`
- `voided_at`
- `void_reason`
- `metadata`
- timestamps

Recommended statuses:

- `draft`
- `posted`
- `void`

### fixed_asset_disposals

Purpose: sale, write-off, and disposal workflow.

Recommended fields:

- `id`
- `disposal_number`
- `fixed_asset_id`
- `disposal_date`
- `disposal_type`
- `disposed_quantity`
- `disposal_cost_amount`
- `disposal_accumulated_depreciation_amount`
- `disposal_net_book_value`
- `proceeds_amount`
- `net_book_value`
- `gain_loss_amount`
- `cash_bank_account_id`
- `journal_entry_id`
- `status`
- `reason`
- `posted_by`
- `posted_at`
- `voided_by`
- `voided_at`
- `void_reason`
- `metadata`
- timestamps

Recommended disposal types:

- `sale`
- `write_off`
- `scrap`
- `lost`

## Accounting Postings

### Capitalization

Capitalization must use fixed asset clearing in MVP.

```text
Dr Fixed Asset
Cr Fixed Asset Clearing
```

Vendor bill acquisition is also part of MVP:

```text
Dr Fixed Asset Clearing
Cr Accounts Payable
```

Manual cash/bank capitalization is intentionally not the default MVP flow. If an asset is bought directly with cash or bank, it should still be represented through a purchase document or a controlled clearing transaction so the fixed asset register, AP/cash trail, and journal history stay consistent.

### Depreciation / Amortization

```text
Dr Depreciation or Amortization Expense
Cr Accumulated Depreciation or Accumulated Amortization
```

### Disposal / Sale

Basic sale disposal:

```text
Dr Cash/Bank or Receivable
Dr Accumulated Depreciation
Cr Fixed Asset
Dr/Cr Gain or Loss on Disposal
```

Write-off:

```text
Dr Accumulated Depreciation
Dr Loss on Disposal
Cr Fixed Asset
```

## Account Mappings

Add mappings:

- `fixed_assets.asset_cost`
- `fixed_assets.accumulated_depreciation`
- `fixed_assets.depreciation_expense`
- `fixed_assets.disposal_gain`
- `fixed_assets.disposal_loss`
- `fixed_assets.clearing`
- `fixed_assets.accumulated_amortization`
- `fixed_assets.amortization_expense`
- `fixed_assets.impairment_loss`

Category-level account fields should override global mappings when present.

## Document Number Types

Add document number types:

- `fixed_asset`
- `fixed_asset_capitalization`
- `fixed_asset_depreciation`
- `fixed_asset_disposal`

Suggested prefixes:

- `FA`
- `FAC`
- `FAD`
- `FAS`

## Permissions

Add permission keys:

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

## API Routes

Prefix: `/api/fixed-assets`

Recommended routes:

- `GET /categories`
- `POST /categories`
- `GET /categories/{id}`
- `PATCH /categories/{id}`
- `PATCH /categories/{id}/activate`
- `PATCH /categories/{id}/deactivate`
- `GET /assets`
- `POST /assets`
- `GET /assets/{id}`
- `PATCH /assets/{id}`
- `POST /assets/{id}/capitalize`
- `POST /assets/{id}/generate-depreciation`
- `POST /depreciation-runs`
- `GET /depreciation-runs`
- `GET /depreciation-runs/{id}`
- `POST /depreciation-runs/{id}/post`
- `POST /depreciation-runs/{id}/void`
- `POST /assets/{id}/dispose`
- `GET /reports/register`
- `GET /reports/depreciation`
- `GET /reports/disposals`

## MVP Boundary

MVP should include:

- Categories
- Asset register
- Fixed asset acquisition from vendor bill lines through fixed asset clearing
- Capitalization from fixed asset clearing
- Straight-line depreciation/amortization
- Non-depreciable and impairment-only categories
- Depreciation run posting
- Disposal/write-off
- Basic reports

MVP should defer:

- Declining balance methods
- Units-of-production method
- Full valuation adjustment workflow covering revaluation and impairment
- Asset maintenance scheduling
- Barcode/QR tracking

Pre-production required before production ready:

- Fixed asset valuation adjustment workflow covering revaluation and impairment
