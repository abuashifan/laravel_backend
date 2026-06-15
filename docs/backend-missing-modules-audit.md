# Backend Missing Modules Audit

Date: 2026-06-15
Scope: Laravel backend tenant migrations, module API routes, permissions, settings flags, and backend-local docs.

## Current Backend Coverage

The backend currently exposes these main API modules through `routes/api.php`:

- Auth and company selection
- Tenant diagnostics
- Company settings
- Access management
- Accounting controls: fiscal year status, fiscal closing, period locks, account mapping health
- Master data: chart of accounts, contacts, payment terms, units, product categories, products, warehouses, departments, projects, account mappings
- Manual journal
- Financial reports
- Sales workflow and AR
- Purchase workflow and AP
- Cash and bank
- Inventory

Tenant migrations cover master data, general ledger, sales, purchase, cash-bank, inventory, payment terms, audit logs, and transaction revisions.

## Confirmed Missing Or Partial Modules

### 1. Fixed Assets

Priority: highest.

Evidence:

- `company_module_settings.fixed_asset_enabled` exists as a company module flag.
- Frontend has a `fixed-assets` module placeholder.
- No backend module route, migration, model, service, request, controller, permission catalog entries, document numbering entries, or account mappings exist for fixed assets.

Recommended backend scope:

- Asset categories
- Fixed asset register
- Acquisition and capitalization
- Depreciation methods and schedules
- Depreciation posting
- Disposal, sale, write-off
- Asset revaluation or impairment, if needed later
- Fixed asset reports
- Account mappings for asset cost, accumulated depreciation, depreciation expense, disposal gain/loss

### 2. Opening Balance

Priority: high.

Evidence:

- Opening balance service/support/config exists.
- `opening_balance` document number and account mapping exist.
- There is no tenant API module for creating, validating, previewing, posting, voiding, or locking opening balances.

Recommended backend scope:

- Opening balance batch endpoint
- Preview and validation endpoint
- Post to journal endpoint
- Void/reversal endpoint if allowed by policy
- Opening balance status for onboarding
- Guardrails so opening balances cannot be changed after real transactions unless explicitly allowed

### 3. Opening Stock And Stock Transfer

Priority: high.

Evidence:

- `opening_stock` and `stock_transfer` document numbers exist.
- Inventory movement types include `opening_stock`.
- Inventory routes expose stock balances, stock movements, stock adjustments, stock opname, valuation, and reports.
- There is no dedicated transfer workflow API or opening stock workflow API.

Recommended backend scope:

- Stock transfer header and lines, or a dedicated stock movement workflow with source pairing
- Transfer issue and receipt lifecycle if warehouses need in-transit stock
- Opening stock input, import, validation, and post
- Permission catalog entries for transfer and opening stock

### 4. Tax

Priority: medium-high.

Evidence:

- `tax_enabled` exists in accounting and module settings.
- Sales and purchase documents contain tax fields.
- Account mappings include sales output tax and purchase input tax.
- There is no tax master module, tax rate API, tax code API, tax report, or tax settlement workflow.

Recommended backend scope:

- Tax rates and tax codes
- Sales and purchase tax classification
- Tax summary and detail reports
- VAT input/output reconciliation
- Tax settlement/payment journal

### 5. Approval Workflow

Priority: medium-high.

Evidence:

- `approval_enabled` exists in accounting and module settings.
- Several documents have approve actions.
- There is no generic approval matrix, approval inbox, approval delegation, or per-document approval rule module.

Recommended backend scope:

- Approval rules per document type
- Approval levels and approver assignment
- Approval requests/inbox
- Approve/reject history
- Delegation or substitute approver support

### 6. Budgeting And Budget Requests

Priority: medium.

Evidence:

- No tenant tables or API routes exist for budgets.
- Product roadmap mentions budget requests.

Recommended backend scope:

- Budget periods
- Budget lines by account, department, and project
- Budget request workflow
- Budget approval
- Budget vs actual reports

### 7. Employee Portal / HR Lite

Priority: medium.

Evidence:

- Contacts have an `is_employee` flag only.
- No employee profile, employee transaction, reimbursement, cash advance, or payroll APIs exist.
- Product roadmap mentions employee portal.

Recommended backend scope:

- Employee profile extension
- Reimbursements
- Employee cash advances and settlements
- Optional payroll module later
- Employee self-service APIs if portal work starts

### 8. Manufacturing

Priority: lower unless production businesses become near-term target.

Evidence:

- No BOM, production order, WIP, material issue, or finished goods receipt tables/routes exist.
- Product roadmap mentions manufacturing.

Recommended backend scope:

- Bill of materials
- Production orders
- Material issue
- WIP accounting
- Finished goods receipt
- Production variance

### 9. Advanced Multi-Currency

Priority: foundation work before claiming full multi-currency support.

Evidence:

- Base currency and transaction currency/exchange rate fields exist in several places.
- No currency master, exchange rate table, realized/unrealized forex gain/loss service, or AR/AP/bank revaluation module exists.

Recommended backend scope:

- Currency master
- Exchange rates
- Realized forex gain/loss on settlement
- Period-end revaluation
- Currency-aware financial reports

## Suggested Implementation Order

1. Fixed Assets
2. Opening Balance
3. Opening Stock and Stock Transfer
4. Tax module
5. Approval workflow
6. Budgeting
7. Employee Portal / HR Lite
8. Manufacturing
9. Advanced Multi-Currency

## Integration Notes

- Keep backend tenant API contracts, permission middleware, posting rules, journal immutability, and void/reversal behavior consistent with existing Sales, Purchase, CashBank, Inventory, and Journal modules.
- Add permissions and document numbering before exposing new workflows.
- For modules that post accounting impact, add account mappings and account mapping health coverage.
- For frontend/backend integration, verify permission key spelling. One known mismatch from the audit: frontend used `inventory.opnames.view`, while backend currently uses `inventory.opname.view`.
