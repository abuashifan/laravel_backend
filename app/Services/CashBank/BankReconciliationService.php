<?php

namespace App\Services\CashBank;

use App\Exceptions\ApiException;
use App\Models\Tenant\BankReconciliation;
use App\Models\Tenant\BankReconciliationLine;
use App\Models\Tenant\JournalEntryLine;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Tenant\TenantContext;
use App\Support\DocumentNumbering\DocumentType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BankReconciliationService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly CashBankAccountService $cashBankAccountService,
    ) {}

    public function list(array $filters = []): Collection
    {
        $query = BankReconciliation::query()->with('cashBankAccount');
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['cash_bank_account_id'])) {
            $query->where('cash_bank_account_id', (int) $filters['cash_bank_account_id']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('statement_end_date', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('statement_start_date', '<=', (string) $filters['date_to']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.str_replace('%', '', (string) $filters['search']).'%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('reconciliation_number', 'like', $term)
                    ->orWhere('notes', 'like', $term)
                    ->orWhereHas('cashBankAccount', fn ($account) => $account
                        ->where('account_code', 'like', $term)
                        ->orWhere('account_name', 'like', $term));
            });
        }

        return $query->orderByDesc('statement_end_date')->orderByDesc('id')->get();
    }

    public function find(int $id): BankReconciliation
    {
        return BankReconciliation::query()->with('lines', 'cashBankAccount')->findOrFail($id);
    }

    public function create(array $data): BankReconciliation
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        $cashAccountId = (int) $data['cash_bank_account_id'];
        if (! $this->cashBankAccountService->isCashBankAccount($cashAccountId)) {
            throw ApiException::make('CASH_BANK_ACCOUNT_REQUIRED', 'Cash/bank account must be a cash/bank marked COA.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($company, $data) {
            $rec = BankReconciliation::query()->create([
                'reconciliation_number' => $this->documentNumberService->generate($company, DocumentType::BANK_RECONCILIATION, (string) $data['statement_end_date']),
                'cash_bank_account_id' => (int) $data['cash_bank_account_id'],
                'statement_start_date' => (string) $data['statement_start_date'],
                'statement_end_date' => (string) $data['statement_end_date'],
                'statement_opening_balance' => (float) ($data['statement_opening_balance'] ?? 0),
                'statement_ending_balance' => (float) ($data['statement_ending_balance'] ?? 0),
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->refreshLines($rec);

            return $rec->refresh()->load('lines', 'cashBankAccount');
        });
    }

    public function update(BankReconciliation $rec, array $data): BankReconciliation
    {
        if ($rec->status !== 'draft') {
            throw ApiException::make('RECONCILIATION_NOT_EDITABLE', 'Only draft reconciliation can be updated.', 422);
        }

        $rec->forceFill([
            'statement_opening_balance' => array_key_exists('statement_opening_balance', $data) ? (float) $data['statement_opening_balance'] : $rec->statement_opening_balance,
            'statement_ending_balance' => array_key_exists('statement_ending_balance', $data) ? (float) $data['statement_ending_balance'] : $rec->statement_ending_balance,
            'notes' => $data['notes'] ?? $rec->notes,
            'metadata' => $data['metadata'] ?? $rec->metadata,
        ])->save();

        return $rec->refresh()->load('lines', 'cashBankAccount');
    }

    public function refreshLines(BankReconciliation $rec, bool $resetCleared = false): BankReconciliation
    {
        if ($rec->status !== 'draft') {
            throw ApiException::make('RECONCILIATION_NOT_EDITABLE', 'Only draft reconciliation can refresh lines.', 422);
        }

        $rec->loadMissing('lines');
        $existing = $rec->lines->keyBy(fn (BankReconciliationLine $line) => (int) $line->journal_entry_line_id);

        $start = Carbon::parse((string) $rec->statement_start_date)->toDateString();
        $end = Carbon::parse((string) $rec->statement_end_date)->toDateString();

        $rows = JournalEntryLine::query()
            ->select([
                'journal_entry_lines.id as journal_entry_line_id',
                'journal_entry_lines.journal_entry_id as journal_entry_id',
                'journal_entry_lines.debit as debit',
                'journal_entry_lines.credit as credit',
                'journal_entries.journal_date as journal_date',
                'journal_entries.journal_number as journal_number',
                'journal_entries.description as description',
            ])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.is_obsolete', 0)
            ->where('journal_entry_lines.account_id', (int) $rec->cash_bank_account_id)
            ->whereDate('journal_entries.journal_date', '>=', $start)
            ->whereDate('journal_entries.journal_date', '<=', $end)
            ->orderBy('journal_entries.journal_date')
            ->orderBy('journal_entries.journal_number')
            ->orderBy('journal_entry_lines.line_order')
            ->get();

        $order = 1;
        $retainedIds = [];
        foreach ($rows as $r) {
            $previous = $existing->get((int) $r->journal_entry_line_id);
            $line = BankReconciliationLine::query()->updateOrCreate([
                'bank_reconciliation_id' => (int) $rec->id,
                'journal_entry_line_id' => (int) $r->journal_entry_line_id,
            ], [
                'bank_reconciliation_id' => (int) $rec->id,
                'journal_entry_id' => (int) $r->journal_entry_id,
                'journal_date' => (string) $r->journal_date,
                'journal_number' => (string) $r->journal_number,
                'description' => $r->description,
                'debit' => (float) $r->debit,
                'credit' => (float) $r->credit,
                'is_cleared' => $resetCleared ? false : (bool) ($previous?->is_cleared ?? false),
                'cleared_date' => $resetCleared ? null : $previous?->cleared_date,
                'line_order' => $order++,
            ]);
            $retainedIds[] = (int) $line->id;
        }

        BankReconciliationLine::query()
            ->where('bank_reconciliation_id', $rec->id)
            ->when($retainedIds !== [], fn ($query) => $query->whereNotIn('id', $retainedIds))
            ->when($retainedIds === [], fn ($query) => $query)
            ->delete();

        return $rec->refresh()->load('lines', 'cashBankAccount');
    }

    public function markLines(BankReconciliation $rec, array $lineIds, bool $cleared, ?string $clearedDate = null): BankReconciliation
    {
        if ($rec->status !== 'draft') {
            throw ApiException::make('RECONCILIATION_NOT_EDITABLE', 'Only draft reconciliation can be updated.', 422);
        }

        $date = $clearedDate ? Carbon::parse($clearedDate)->toDateString() : null;
        if ($cleared && $date !== null) {
            $start = Carbon::parse((string) $rec->statement_start_date)->toDateString();
            $end = Carbon::parse((string) $rec->statement_end_date)->toDateString();
            if ($date < $start || $date > $end) {
                throw ApiException::make('CLEARED_DATE_OUTSIDE_STATEMENT_PERIOD', 'Cleared date must be within the statement period.', 422, [
                    'cleared_date' => ["Cleared date must be between {$start} and {$end}."],
                ]);
            }
        }

        BankReconciliationLine::query()
            ->where('bank_reconciliation_id', $rec->id)
            ->whereIn('id', array_map('intval', $lineIds))
            ->update([
                'is_cleared' => $cleared,
                'cleared_date' => $cleared ? $date : null,
            ]);

        return $rec->refresh()->load('lines', 'cashBankAccount');
    }

    public function finalize(BankReconciliation $rec): BankReconciliation
    {
        if ($rec->status === 'finalized') {
            return $rec->refresh()->load('lines', 'cashBankAccount');
        }
        if ($rec->status !== 'draft') {
            throw ApiException::make('RECONCILIATION_NOT_FINALIZABLE', 'Only draft reconciliation can be finalized.', 422);
        }

        $rec->loadMissing('lines');
        $clearedNet = $rec->lines
            ->where('is_cleared', true)
            ->sum(fn (BankReconciliationLine $line): float => (float) $line->debit - (float) $line->credit);
        $difference = (float) $rec->statement_opening_balance + $clearedNet - (float) $rec->statement_ending_balance;

        if (abs($difference) > 0.01) {
            throw ApiException::make('RECONCILIATION_NOT_BALANCED', 'Bank reconciliation difference must be zero before finalizing.', 422, [
                'difference' => ['Opening balance plus cleared net movement must equal ending balance.'],
            ], ['difference' => round($difference, 2)]);
        }

        $rec->forceFill([
            'status' => 'finalized',
            'posted_by' => auth()->id(),
            'posted_at' => now(),
        ])->save();

        return $rec->refresh()->load('lines', 'cashBankAccount');
    }

    public function reopen(BankReconciliation $rec, string $reason): BankReconciliation
    {
        if ($rec->status !== 'finalized') {
            throw ApiException::make('RECONCILIATION_NOT_REOPENABLE', 'Only finalized reconciliation can be reopened.', 422);
        }

        $rec->forceFill([
            'status' => 'draft',
            'reopened_by' => auth()->id(),
            'reopened_at' => now(),
            'reopen_reason' => $reason,
        ])->save();

        return $rec->refresh()->load('lines', 'cashBankAccount');
    }
}
