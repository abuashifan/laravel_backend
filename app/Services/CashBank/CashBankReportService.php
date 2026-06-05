<?php

namespace App\Services\CashBank;

use App\Exceptions\ApiException;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\JournalEntryLine;
use App\Services\Reports\LedgerBalanceCalculator;
use Carbon\Carbon;

class CashBankReportService
{
    public function __construct(
        private readonly CashBankAccountService $cashBankAccountService,
        private readonly LedgerBalanceCalculator $balanceCalculator,
    ) {
    }

    public function accountStatement(int $cashBankAccountId, ?string $startDate = null, ?string $endDate = null): array
    {
        if (! $this->cashBankAccountService->isCashBankAccount($cashBankAccountId)) {
            throw ApiException::make('CASH_BANK_ACCOUNT_REQUIRED', 'Cash/bank account must be a cash/bank marked COA.', 422);
        }

        $account = ChartOfAccount::query()->findOrFail($cashBankAccountId);
        $normalBalance = (string) ($account->normal_balance ?: 'debit');

        $opening = 0.0;
        if ($startDate) {
            $start = Carbon::parse($startDate)->toDateString();
            $row = $this->baseCashBankJournalQuery($cashBankAccountId)
                ->whereDate('journal_entries.journal_date', '<', $start)
                ->selectRaw('COALESCE(SUM(journal_entry_lines.debit),0) as debit_total, COALESCE(SUM(journal_entry_lines.credit),0) as credit_total')
                ->first();

            $opening = $this->balanceCalculator->openingBalance((float) $row->debit_total, (float) $row->credit_total, $normalBalance);
        }

        $q = $this->baseCashBankJournalQuery($cashBankAccountId);
        if ($startDate) $q->whereDate('journal_entries.journal_date', '>=', Carbon::parse($startDate)->toDateString());
        if ($endDate) $q->whereDate('journal_entries.journal_date', '<=', Carbon::parse($endDate)->toDateString());

        $rows = $q->select([
            'journal_entry_lines.id as journal_entry_line_id',
            'journal_entry_lines.journal_entry_id as journal_entry_id',
            'journal_entries.journal_number as journal_number',
            'journal_entries.journal_date as journal_date',
            'journal_entries.description as description',
            'journal_entry_lines.debit as debit',
            'journal_entry_lines.credit as credit',
            'journal_entries.source_type as source_type',
            'journal_entries.source_number as source_number',
            'journal_entries.source_module as source_module',
        ])
            ->orderBy('journal_entries.journal_date')
            ->orderBy('journal_entries.journal_number')
            ->orderBy('journal_entry_lines.line_order')
            ->get();

        $running = $opening;
        $lines = [];
        $periodDebit = 0.0;
        $periodCredit = 0.0;

        foreach ($rows as $r) {
            $debit = (float) $r->debit;
            $credit = (float) $r->credit;
            $periodDebit += $debit;
            $periodCredit += $credit;
            $running = $this->balanceCalculator->runningBalance($running, $debit, $credit, $normalBalance);

            $lines[] = [
                'journal_entry_id' => (int) $r->journal_entry_id,
                'journal_entry_line_id' => (int) $r->journal_entry_line_id,
                'journal_number' => (string) $r->journal_number,
                'journal_date' => (string) Carbon::parse((string) $r->journal_date)->toDateString(),
                'description' => $r->description,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => (float) $running,
                'source_type' => $r->source_type,
                'source_number' => $r->source_number,
                'source_module' => $r->source_module,
            ];
        }

        $ending = $this->balanceCalculator->endingBalance($opening, $periodDebit, $periodCredit, $normalBalance);

        return [
            'account' => [
                'id' => (int) $account->id,
                'account_code' => (string) $account->account_code,
                'account_name' => (string) $account->account_name,
                'normal_balance' => (string) $account->normal_balance,
                'is_active' => (bool) $account->is_active,
            ],
            'filter' => [
                'cash_bank_account_id' => $cashBankAccountId,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'opening_balance' => (float) $opening,
            'period_totals' => [
                'debit' => (float) $periodDebit,
                'credit' => (float) $periodCredit,
            ],
            'ending_balance' => (float) $ending,
            'lines' => $lines,
        ];
    }

    private function baseCashBankJournalQuery(int $cashBankAccountId)
    {
        return JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.is_obsolete', 0)
            ->where('journal_entry_lines.account_id', $cashBankAccountId);
    }
}

