<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\JournalEntryLine;
use App\Models\Tenant\SalesInvoice;

class ARReconciliationService
{
    public function __construct(private readonly ARSubsidiaryLedgerService $ledgerService) {}

    public function compareSubsidiaryToGL(array $filters = []): array
    {
        $subsidiary = $this->getSubsidiaryBalance($filters);
        $gl = $this->getGLARBalance($filters);
        $difference = round($subsidiary - $gl, 2);

        return [
            'subsidiary_balance' => $subsidiary,
            'gl_ar_balance' => $gl,
            'difference' => $difference,
            'is_reconciled' => abs($difference) < 0.01,
        ];
    }

    public function getGLARBalance(array $filters = []): float
    {
        $accountIds = $this->arAccountIds();
        if ($accountIds === []) {
            return 0.0;
        }

        $query = JournalEntryLine::query()
            ->whereIn('account_id', $accountIds)
            ->whereHas('journalEntry', function ($journal) use ($filters) {
                $journal->where('status', 'posted')
                    ->where('is_obsolete', false)
                    ->whereNull('voided_at')
                    ->when($filters['start_date'] ?? null, fn ($query, $date) => $query->whereDate('journal_date', '>=', $date))
                    ->when($filters['end_date'] ?? $filters['as_of_date'] ?? null, fn ($query, $date) => $query->whereDate('journal_date', '<=', $date));
            });

        return round((float) $query->sum('debit') - (float) $query->sum('credit'), 2);
    }

    public function getSubsidiaryBalance(array $filters = []): float
    {
        $movements = $this->ledgerService->movements($filters);

        return round((float) collect($movements)->sum('debit') - (float) collect($movements)->sum('credit'), 2);
    }

    /**
     * @return array<int,int>
     */
    private function arAccountIds(): array
    {
        $snapshotIds = SalesInvoice::query()
            ->whereNotNull('ar_account_id')
            ->whereNotIn('status', ['draft', 'approved', 'void'])
            ->pluck('ar_account_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $mappingIds = AccountMapping::query()
            ->whereIn('mapping_key', ['sales.accounts_receivable', 'accounts_receivable'])
            ->where('is_active', true)
            ->whereNotNull('account_id')
            ->pluck('account_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $ids = array_values(array_unique(array_filter([...$snapshotIds, ...$mappingIds])));
        if ($ids === []) {
            throw ApiException::make('ACCOUNT_MAPPING_MISSING', 'Required account mapping is missing: sales.accounts_receivable', 422);
        }

        return $ids;
    }
}
