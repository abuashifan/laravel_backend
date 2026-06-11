<?php

namespace App\Services\Purchase;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\JournalEntryLine;
use App\Models\Tenant\VendorBill;

class APReconciliationService
{
    public function __construct(private readonly APSubsidiaryLedgerService $ledgerService)
    {
    }

    public function compareSubsidiaryToGL(array $filters = []): array
    {
        $subsidiary = $this->getSubsidiaryBalance($filters);
        $gl = $this->getGLAPBalance($filters);
        $difference = round($subsidiary - $gl, 2);

        return [
            'subsidiary_balance' => $subsidiary,
            'gl_ap_balance' => $gl,
            'difference' => $difference,
            'is_reconciled' => abs($difference) < 0.01,
        ];
    }

    public function getGLAPBalance(array $filters = []): float
    {
        $accountIds = $this->apAccountIds();

        $query = JournalEntryLine::query()
            ->whereIn('account_id', $accountIds)
            ->whereHas('journalEntry', function ($journal) use ($filters) {
                $journal->where('status', 'posted')
                    ->where('is_obsolete', false)
                    ->whereNull('voided_at')
                    ->when($filters['start_date'] ?? null, fn ($query, $date) => $query->where('journal_date', '>=', $date))
                    ->when($filters['end_date'] ?? $filters['as_of_date'] ?? null, fn ($query, $date) => $query->where('journal_date', '<=', $date));
            });

        return round((float) $query->sum('credit') - (float) $query->sum('debit'), 2);
    }

    public function getSubsidiaryBalance(array $filters = []): float
    {
        $movements = $this->ledgerService->movements($filters);

        return round((float) collect($movements)->sum('credit') - (float) collect($movements)->sum('debit'), 2);
    }

    private function apAccountIds(): array
    {
        $snapshotIds = VendorBill::query()
            ->whereNotNull('ap_account_id')
            ->whereNotIn('status', ['draft', 'approved', 'void'])
            ->whereNotNull('posted_at')
            ->pluck('ap_account_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $mappingIds = AccountMapping::query()
            ->whereIn('mapping_key', ['purchase.accounts_payable', 'purchase.payable'])
            ->where('is_active', true)
            ->whereNotNull('account_id')
            ->pluck('account_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $ids = array_values(array_unique(array_merge($snapshotIds, $mappingIds)));
        if ($ids === []) {
            throw ApiException::make('ACCOUNT_MAPPING_MISSING', 'Required account mapping is missing: purchase.accounts_payable', 422);
        }

        return $ids;
    }
}
