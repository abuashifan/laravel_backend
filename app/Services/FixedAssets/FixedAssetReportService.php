<?php

namespace App\Services\FixedAssets;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\FixedAsset;
use App\Models\Tenant\FixedAssetDepreciationSchedule;
use App\Models\Tenant\FixedAssetDisposal;
use App\Models\Tenant\JournalEntryLine;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FixedAssetReportService
{
    public function register(string $period): array
    {
        return FixedAsset::query()->with('category', 'department', 'project')->get()->map(fn (FixedAsset $asset): array => [
            'asset_number' => $asset->asset_number,
            'asset_name' => $asset->name,
            'category' => $asset->category?->name,
            'asset_class' => $asset->asset_class,
            'acquisition_date' => $asset->acquisition_date?->toDateString(),
            'service_start_date' => $asset->service_start_date?->toDateString(),
            'useful_life_years' => $asset->useful_life_years,
            'acquisition_cost' => (float) $asset->acquisition_cost,
            'depreciation_period_total' => (float) $asset->schedules()->where('period', $period)->where('status', 'posted')->sum('depreciation_amount'),
            'depreciation_current_year' => (float) $asset->schedules()->where('period', 'like', substr($period, 0, 4).'-%')->where('period', '<=', $period)->where('status', 'posted')->sum('depreciation_amount'),
            'accumulated_depreciation_until_period' => (float) $asset->schedules()->where('period', '<=', $period)->where('status', 'posted')->sum('depreciation_amount'),
            'net_book_value_as_of_period' => (float) $asset->net_book_value,
            'quantity' => (float) $asset->quantity,
            'remaining_quantity' => (float) $asset->remaining_quantity,
            'status' => $asset->status,
            'department' => $asset->department?->name,
            'project' => $asset->project?->name,
        ])->all();
    }

    public function depreciation(?string $from, string $to, string $mode = 'detail'): array
    {
        $from ??= substr($to, 0, 4).'-01';
        $query = FixedAssetDepreciationSchedule::query()->with('asset.category')
            ->whereBetween('period', [$from, $to]);

        if ($mode === 'yearly_summary') {
            return $query->get()->groupBy(fn ($row) => substr((string) $row->period, 0, 4).'|'.$row->fixed_asset_id)->map(function (Collection $rows): array {
                $row = $rows->first();
                return [
                    'year' => substr((string) $row->period, 0, 4),
                    'asset_number' => $row->asset?->asset_number,
                    'asset_name' => $row->asset?->name,
                    'depreciation_year_total' => (float) $rows->sum('depreciation_amount'),
                    'accumulated_depreciation_end_of_year' => (float) $rows->max('accumulated_depreciation_after'),
                    'net_book_value_end_of_year' => (float) $rows->last()?->net_book_value_after,
                ];
            })->values()->all();
        }

        return $query->orderBy('period')->get()->map(fn ($row): array => [
            'period' => $row->period,
            'asset_number' => $row->asset?->asset_number,
            'asset_name' => $row->asset?->name,
            'category' => $row->asset?->category?->name,
            'depreciation_amount' => (float) $row->depreciation_amount,
            'accumulated_depreciation_after' => (float) $row->accumulated_depreciation_after,
            'net_book_value_after' => (float) $row->net_book_value_after,
            'journal_entry_id' => $row->journal_entry_id,
            'status' => $row->status,
        ])->all();
    }

    public function disposals(array $filters = []): array
    {
        $query = FixedAssetDisposal::query()->with('asset');
        if (! empty($filters['disposal_date_from'])) $query->whereDate('disposal_date', '>=', $filters['disposal_date_from']);
        if (! empty($filters['disposal_date_to'])) $query->whereDate('disposal_date', '<=', $filters['disposal_date_to']);
        return $query->orderByDesc('disposal_date')->get()->toArray();
    }

    public function reconciliation(string $period): array
    {
        $asOfDate = Carbon::createFromFormat('Y-m', $period)->endOfMonth()->toDateString();
        $registerCost = (float) FixedAsset::query()->sum('acquisition_cost');
        $registerAccumulated = (float) FixedAsset::query()->sum('accumulated_depreciation');
        $costAccounts = $this->mappingAccountIds(['fixed_assets.cost']);
        $accumulatedAccounts = $this->mappingAccountIds(['fixed_assets.accumulated_depreciation', 'fixed_assets.accumulated_amortization']);
        $glCost = $this->glBalance($costAccounts, $asOfDate, normalDebit: true);
        $glAccumulated = $this->glBalance($accumulatedAccounts, $asOfDate, normalDebit: false);

        return [
            'period' => $period,
            'asset_register_cost_total' => $registerCost,
            'asset_register_accumulated_depreciation' => $registerAccumulated,
            'asset_register_net_book_value' => $registerCost - $registerAccumulated,
            'gl_fixed_asset_cost_balance' => $glCost,
            'gl_accumulated_depreciation_balance' => $glAccumulated,
            'gl_net_book_value' => $glCost - $glAccumulated,
            'difference_cost' => round($registerCost - $glCost, 2),
            'difference_accumulated_depreciation' => round($registerAccumulated - $glAccumulated, 2),
        ];
    }

    private function mappingAccountIds(array $keys): array
    {
        return AccountMapping::query()
            ->whereIn('mapping_key', $keys)
            ->where('is_active', true)
            ->whereNotNull('account_id')
            ->pluck('account_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function glBalance(array $accountIds, string $asOfDate, bool $normalDebit): float
    {
        if ($accountIds === []) {
            return 0.0;
        }

        $row = JournalEntryLine::query()
            ->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.account_id', $accountIds)
            ->where('je.status', 'posted')
            ->where('je.is_obsolete', false)
            ->whereDate('je.journal_date', '<=', $asOfDate)
            ->selectRaw('SUM(journal_entry_lines.debit) as debit_total, SUM(journal_entry_lines.credit) as credit_total')
            ->first();

        $debit = (float) ($row?->debit_total ?? 0);
        $credit = (float) ($row?->credit_total ?? 0);

        return round($normalDebit ? $debit - $credit : $credit - $debit, 2);
    }
}
