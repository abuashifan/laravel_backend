# Phase 27 — Cash & Bank Contract and Reconciliation

Date: 2026-06-21

## Canonical contract

- Cash receipt/payment allocation lines are required and must reconcile to the header amount.
- Draft receipt, payment, and transfer documents are editable through PATCH endpoints.
- Cash/bank selectors use active chart-of-account rows marked `is_cash_bank`.
- List search, status, and date filters execute before pagination.
- Reconciliation lines expose `journal_date`, `journal_number`, `debit`, and `credit`.
- Refresh identifies lines by `journal_entry_line_id` and preserves cleared state by default.
- Cleared dates must be inside the statement period.
- Difference is `opening_balance + cleared_debit - cleared_credit - ending_balance`.
- Finalize requires a zero difference and locks the reconciliation.
- Reopen requires an audit reason and returns the reconciliation to draft.

## Schema

Migration `2026_06_21_000001_add_reopen_audit_to_bank_reconciliations_table.php`
adds `reopened_by`, `reopened_at`, and `reopen_reason`.

## Verification

- `php artisan test --filter=CashBank`
- Pint on changed CashBank files
