<?php

namespace App\Services\FixedAssets;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\FixedAsset;
use App\Models\Tenant\FixedAssetCategory;
use App\Models\Tenant\FixedAssetDepreciationRun;
use App\Models\Tenant\FixedAssetDepreciationSchedule;
use App\Models\Tenant\FixedAssetDisposal;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\VendorBillLine;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Tenant\TenantContext;
use App\Support\DocumentNumbering\DocumentType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FixedAssetService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function categories(array $filters = []): Collection
    {
        $query = FixedAssetCategory::query();
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['asset_class'])) {
            $query->where('asset_class', (string) $filters['asset_class']);
        }
        return $query->orderBy('name')->get();
    }

    public function createCategory(array $data): FixedAssetCategory
    {
        return FixedAssetCategory::query()->create(array_merge($data, [
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]));
    }

    public function updateCategory(FixedAssetCategory $category, array $data): FixedAssetCategory
    {
        $category->fill($data)->save();
        return $category->refresh();
    }

    public function list(array $filters = []): Collection
    {
        $query = FixedAsset::query()->with('category', 'department', 'project');
        if (! empty($filters['status'])) $query->where('status', (string) $filters['status']);
        if (! empty($filters['category_id'])) $query->where('fixed_asset_category_id', (int) $filters['category_id']);
        if (! empty($filters['asset_class'])) $query->where('asset_class', (string) $filters['asset_class']);
        return $query->orderBy('asset_number')->orderByDesc('id')->get();
    }

    public function find(int $id): FixedAsset
    {
        return FixedAsset::query()
            ->with('category', 'department', 'project', 'acquisitions', 'schedules', 'disposals', 'transactions')
            ->findOrFail($id);
    }

    public function create(array $data): FixedAsset
    {
        $category = FixedAssetCategory::query()->findOrFail((int) $data['fixed_asset_category_id']);
        $payload = $this->assetPayload($data, $category);

        return DB::connection('tenant')->transaction(function () use ($payload) {
            $asset = FixedAsset::query()->create($payload);
            $this->transaction($asset, 'acquisition', (string) $asset->acquisition_date?->toDateString(), (float) $asset->acquisition_cost, (float) $asset->quantity, [
                'source_type' => $asset->source_type,
                'source_id' => $asset->source_id,
            ]);
            $this->audit('fixed_asset.created', $asset, 'Fixed asset draft created.');
            return $asset->refresh()->load('category');
        });
    }

    public function update(FixedAsset $asset, array $data): FixedAsset
    {
        if (FixedAssetDepreciationSchedule::query()->where('fixed_asset_id', $asset->id)->where('status', 'posted')->exists()) {
            throw ApiException::make('FIXED_ASSET_HAS_POSTED_DEPRECIATION', 'Asset with posted depreciation cannot be edited.', 422);
        }
        if (in_array((string) $asset->status, ['disposed', 'partially_disposed'], true)) {
            throw ApiException::make('FIXED_ASSET_NOT_EDITABLE', 'Disposed asset cannot be edited.', 422);
        }

        $category = isset($data['fixed_asset_category_id'])
            ? FixedAssetCategory::query()->findOrFail((int) $data['fixed_asset_category_id'])
            : $asset->category;

        return DB::connection('tenant')->transaction(function () use ($asset, $data, $category) {
            $asset->fill($this->assetPayload(array_merge($asset->toArray(), $data), $category, preserveStatus: true))->save();
            if ($asset->capitalized_at) {
                $this->generateSchedules($asset->refresh());
            }
            $this->audit('fixed_asset.updated', $asset, 'Fixed asset updated.');
            return $asset->refresh()->load('category');
        });
    }

    public function capitalize(FixedAsset $asset, array $data): FixedAsset
    {
        if (in_array((string) $asset->status, ['active', 'capitalized', 'partially_disposed', 'disposed'], true)) {
            throw ApiException::make('FIXED_ASSET_ALREADY_CAPITALIZED', 'Asset is already capitalized or disposed.', 422);
        }

        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);

        $date = (string) ($data['capitalization_date'] ?? $asset->acquisition_date?->toDateString());
        $amount = (float) ($data['amount'] ?? $asset->acquisition_cost);
        if ($amount <= 0 || $amount > (float) $asset->acquisition_cost) {
            throw ApiException::make('INVALID_CAPITALIZATION_AMOUNT', 'Capitalization amount is invalid.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($asset, $company, $date, $amount, $data) {
            $asset->loadMissing('category');
            $sourceLine = $this->lockSourceVendorBillLine($asset, $data);
            if ($sourceLine) {
                $remaining = round((float) $sourceLine->subtotal_after_discount - (float) $sourceLine->capitalized_amount, 2);
                if ($amount > $remaining) {
                    throw ApiException::make('CAPITALIZATION_EXCEEDS_SOURCE_LINE', 'Capitalization amount exceeds remaining vendor bill line amount.', 422);
                }
            }
            $assetNumber = $asset->asset_number ?: $this->documentNumberService->generate($company, DocumentType::FIXED_ASSET, $date);
            $journal = $this->journal($date, 'Fixed asset capitalization '.$assetNumber, 'fixed_asset_capitalization', $asset->id, $assetNumber, [
                ['account_id' => $this->assetAccount($asset), 'description' => 'Fixed Asset', 'debit' => $amount, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $this->clearingAccount($asset), 'description' => 'Fixed Asset Clearing', 'debit' => 0, 'credit' => $amount, 'line_order' => 2],
            ]);
            if ($sourceLine) {
                $sourceLine->capitalized_amount = round((float) $sourceLine->capitalized_amount + $amount, 2);
                $sourceLine->save();
            }

            $asset->forceFill([
                'asset_number' => $assetNumber,
                'status' => $asset->service_start_date ? 'active' : 'capitalized',
                'capitalized_at' => now(),
            ])->save();

            $asset->acquisitions()->create([
                'source_type' => $data['source_type'] ?? $asset->source_type,
                'source_id' => $data['source_id'] ?? $asset->source_id,
                'source_line_id' => $data['source_line_id'] ?? null,
                'vendor_id' => $data['vendor_id'] ?? null,
                'acquisition_date' => $asset->acquisition_date,
                'quantity' => $asset->quantity,
                'amount' => $asset->acquisition_cost,
                'capitalized_amount' => $amount,
                'journal_entry_id' => $journal->id,
                'metadata' => $data['metadata'] ?? null,
            ]);

            $this->transaction($asset, 'capitalization', $date, $amount, (float) $asset->quantity, [
                'journal_entry_id' => $journal->id,
            ]);
            $this->generateSchedules($asset->refresh());
            $this->audit('fixed_asset.capitalized', $asset, 'Fixed asset capitalized.', ['journal_entry_id' => $journal->id]);
            return $asset->refresh()->load('category', 'schedules');
        });
    }

    public function dispose(FixedAsset $asset, array $data): FixedAsset
    {
        if (! in_array((string) $asset->status, ['active', 'capitalized', 'partially_disposed'], true)) {
            throw ApiException::make('FIXED_ASSET_NOT_DISPOSABLE', 'Only capitalized or active assets can be disposed.', 422);
        }

        $disposedQty = (float) $data['disposed_quantity'];
        $remainingQty = (float) $asset->remaining_quantity;
        if ($disposedQty <= 0 || $disposedQty > $remainingQty) {
            throw ApiException::make('INVALID_DISPOSAL_QUANTITY', 'Disposed quantity exceeds remaining quantity.', 422);
        }

        $period = Carbon::parse((string) $data['disposal_date'])->format('Y-m');
        if ($asset->schedules()->where('period', $period)->where('status', 'posted')->exists()) {
            throw ApiException::make('DISPOSAL_PERIOD_ALREADY_DEPRECIATED', 'Disposal period depreciation is already posted.', 422);
        }

        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);

        return DB::connection('tenant')->transaction(function () use ($asset, $data, $disposedQty, $remainingQty, $company, $period) {
            $asset->loadMissing('category');
            $ratio = $disposedQty / $remainingQty;
            $cost = round((float) $asset->acquisition_cost * $ratio, 2);
            $accumulated = round((float) $asset->accumulated_depreciation * $ratio, 2);
            $nbv = round($cost - $accumulated, 2);
            $proceeds = round((float) ($data['proceeds_amount'] ?? 0), 2);
            $gainLoss = round($proceeds - $nbv, 2);
            $date = (string) $data['disposal_date'];
            $number = $this->documentNumberService->generate($company, DocumentType::FIXED_ASSET_DISPOSAL, $date);

            $lines = [];
            if ($proceeds > 0) {
                $accountId = $data['cash_bank_account_id'] ?? $data['receivable_account_id'] ?? null;
                if (! $accountId) {
                    throw ApiException::make('DISPOSAL_PROCEEDS_ACCOUNT_REQUIRED', 'Cash/bank or receivable account is required when proceeds exist.', 422);
                }
                $lines[] = ['account_id' => (int) $accountId, 'description' => 'Disposal proceeds', 'debit' => $proceeds, 'credit' => 0, 'line_order' => 1];
            }
            if ($accumulated > 0) {
                $lines[] = ['account_id' => $this->accumulatedAccount($asset), 'description' => 'Accumulated depreciation disposed', 'debit' => $accumulated, 'credit' => 0, 'line_order' => count($lines) + 1];
            }
            if ($gainLoss < 0) {
                $lines[] = ['account_id' => $this->lossAccount($asset), 'description' => 'Loss on disposal', 'debit' => abs($gainLoss), 'credit' => 0, 'line_order' => count($lines) + 1];
            }
            $lines[] = ['account_id' => $this->assetAccount($asset), 'description' => 'Fixed asset disposed', 'debit' => 0, 'credit' => $cost, 'line_order' => count($lines) + 1];
            if ($gainLoss > 0) {
                $lines[] = ['account_id' => $this->gainAccount($asset), 'description' => 'Gain on disposal', 'debit' => 0, 'credit' => $gainLoss, 'line_order' => count($lines) + 1];
            }

            $journal = $this->journal($date, 'Fixed asset disposal '.$number, 'fixed_asset_disposal', $asset->id, $number, $lines);
            $disposal = $asset->disposals()->create([
                'disposal_number' => $number,
                'disposal_date' => $date,
                'disposal_type' => $data['disposal_type'],
                'disposed_quantity' => $disposedQty,
                'disposal_cost_amount' => $cost,
                'disposal_accumulated_depreciation_amount' => $accumulated,
                'disposal_net_book_value' => $nbv,
                'proceeds_amount' => $proceeds,
                'gain_loss_amount' => $gainLoss,
                'cash_bank_account_id' => $data['cash_bank_account_id'] ?? null,
                'receivable_account_id' => $data['receivable_account_id'] ?? null,
                'journal_entry_id' => $journal->id,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
                'metadata' => $data['metadata'] ?? null,
            ]);

            $newRemainingQty = round($remainingQty - $disposedQty, 4);
            $asset->forceFill([
                'remaining_quantity' => $newRemainingQty,
                'acquisition_cost' => round((float) $asset->acquisition_cost - $cost, 2),
                'accumulated_depreciation' => round((float) $asset->accumulated_depreciation - $accumulated, 2),
                'net_book_value' => round((float) $asset->net_book_value - $nbv, 2),
                'depreciable_basis' => max(0, round((float) $asset->depreciable_basis - $cost, 2)),
                'status' => $newRemainingQty <= 0 ? 'disposed' : 'partially_disposed',
                'disposed_at' => $newRemainingQty <= 0 ? now() : $asset->disposed_at,
            ])->save();

            $this->transaction($asset, 'disposal', $date, $nbv, $disposedQty, [
                'source_type' => 'fixed_asset_disposal',
                'source_id' => $disposal->id,
                'journal_entry_id' => $journal->id,
                'period' => $period,
            ]);
            $this->audit('fixed_asset.disposed', $asset, 'Fixed asset disposed.', ['disposal_id' => $disposal->id, 'journal_entry_id' => $journal->id]);
            return $asset->refresh()->load('category', 'disposals');
        });
    }

    public function postDepreciationPeriod(int $year, int $month): FixedAssetDepreciationRun
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);

        $period = sprintf('%04d-%02d', $year, $month);
        $existing = FixedAssetDepreciationRun::query()->where('period', $period)->where('status', 'posted')->first();
        if ($existing) {
            return $existing->load('lines');
        }

        return DB::connection('tenant')->transaction(function () use ($company, $year, $month, $period) {
            $schedules = FixedAssetDepreciationSchedule::query()
                ->with('asset.category')
                ->where('period', $period)
                ->where('status', 'scheduled')
                ->lockForUpdate()
                ->get();

            $run = FixedAssetDepreciationRun::query()->create([
                'run_number' => $this->documentNumberService->generate($company, DocumentType::FIXED_ASSET_DEPRECIATION, $period.'-01'),
                'period_year' => $year,
                'period_month' => $month,
                'period' => $period,
                'status' => 'draft',
                'metadata' => ['eligible_line_count' => $schedules->count()],
            ]);

            $journal = null;
            if ($schedules->isNotEmpty() && (float) $schedules->sum('depreciation_amount') > 0) {
                $grouped = [];
                foreach ($schedules as $schedule) {
                    $asset = $schedule->asset;
                    if (! $asset) continue;
                    $expense = $this->expenseAccount($asset);
                    $accumulated = $this->accumulatedAccount($asset);
                    $grouped['dr_'.$expense] = ($grouped['dr_'.$expense] ?? 0) + (float) $schedule->depreciation_amount;
                    $grouped['cr_'.$accumulated] = ($grouped['cr_'.$accumulated] ?? 0) + (float) $schedule->depreciation_amount;
                }

                $lines = [];
                foreach ($grouped as $key => $amount) {
                    [$side, $accountId] = explode('_', $key, 2);
                    $lines[] = [
                        'account_id' => (int) $accountId,
                        'description' => $side === 'dr' ? 'Depreciation/Amortization Expense' : 'Accumulated Depreciation/Amortization',
                        'debit' => $side === 'dr' ? round($amount, 2) : 0,
                        'credit' => $side === 'cr' ? round($amount, 2) : 0,
                        'line_order' => count($lines) + 1,
                    ];
                }

                $journal = $this->journal($period.'-01', 'Fixed asset depreciation '.$period, 'fixed_asset_depreciation', $run->id, $run->run_number, $lines);
            }

            foreach ($schedules as $schedule) {
                $asset = $schedule->asset;
                if (! $asset) continue;

                $schedule->status = 'posted';
                $schedule->journal_entry_id = $journal?->id;
                $schedule->save();

                $asset->accumulated_depreciation = round((float) $asset->accumulated_depreciation + (float) $schedule->depreciation_amount, 2);
                $asset->net_book_value = round((float) $asset->acquisition_cost - (float) $asset->accumulated_depreciation, 2);
                $asset->save();

                $run->lines()->create([
                    'fixed_asset_id' => $asset->id,
                    'fixed_asset_depreciation_schedule_id' => $schedule->id,
                    'depreciation_amount' => $schedule->depreciation_amount,
                    'accumulated_depreciation_after' => $schedule->accumulated_depreciation_after,
                    'net_book_value_after' => $schedule->net_book_value_after,
                ]);

                $this->transaction($asset, $asset->depreciation_type === 'amortization' ? 'amortization' : 'depreciation', $period.'-01', (float) $schedule->depreciation_amount, null, [
                    'period' => $period,
                    'journal_entry_id' => $journal?->id,
                    'source_type' => 'fixed_asset_depreciation',
                    'source_id' => $run->id,
                ]);
            }

            $run->status = 'posted';
            $run->journal_entry_id = $journal?->id;
            $run->posted_at = now();
            $run->posted_by = auth()->id();
            $run->save();

            $this->auditLogService->logSuccess([
                'event' => 'fixed_asset.depreciation_posted',
                'module' => 'fixed_assets',
                'action' => 'fixed_asset.depreciation.post',
                'message' => 'Fixed asset depreciation/amortization posted.',
                'record_type' => 'fixed_asset_depreciation_run',
                'record_id' => $run->id,
                'record_number' => $run->run_number,
                'metadata' => ['period' => $period, 'journal_entry_id' => $journal?->id],
            ], tenant: true);

            return $run->refresh()->load('lines');
        });
    }

    private function assetPayload(array $data, FixedAssetCategory $category, bool $preserveStatus = false): array
    {
        $quantity = (float) ($data['quantity'] ?? 1);
        $cost = round((float) ($data['acquisition_cost'] ?? 0), 2);
        $salvage = round((float) ($data['salvage_value'] ?? 0), 2);
        $lifeYears = in_array($category->depreciation_type, ['depreciation', 'amortization'], true)
            ? (int) ($data['useful_life_years'] ?? $category->default_useful_life_years ?? 4)
            : null;
        $serviceStart = $data['service_start_date'] ?? null;
        $firstPeriod = $serviceStart && $lifeYears ? Carbon::parse((string) $serviceStart)->addMonthNoOverflow()->format('Y-m') : null;
        $lastPeriod = $firstPeriod && $lifeYears ? Carbon::createFromFormat('Y-m', $firstPeriod)->addMonthsNoOverflow(($lifeYears * 12) - 1)->format('Y-m') : null;

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'fixed_asset_category_id' => $category->id,
            'asset_class' => $category->asset_class,
            'depreciation_type' => $category->depreciation_type,
            'depreciation_method' => $category->depreciation_type === 'none' ? 'none' : 'straight_line',
            'status' => $preserveStatus ? ($data['status'] ?? 'draft') : 'draft',
            'acquisition_date' => $data['acquisition_date'],
            'service_start_date' => $serviceStart,
            'first_depreciation_period' => $firstPeriod,
            'last_depreciation_period' => $lastPeriod,
            'useful_life_years' => $lifeYears,
            'useful_life_months' => $lifeYears ? $lifeYears * 12 : null,
            'quantity' => $quantity,
            'remaining_quantity' => (float) ($data['remaining_quantity'] ?? $quantity),
            'unit_acquisition_cost' => $quantity > 0 ? round($cost / $quantity, 2) : 0,
            'acquisition_cost' => $cost,
            'salvage_value' => $salage = min($salvage, $cost),
            'depreciable_basis' => max(0, $cost - $salage),
            'accumulated_depreciation' => (float) ($data['accumulated_depreciation'] ?? 0),
            'net_book_value' => $cost - (float) ($data['accumulated_depreciation'] ?? 0),
            'department_id' => $data['department_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ];
    }

    private function generateSchedules(FixedAsset $asset): void
    {
        if (! in_array((string) $asset->depreciation_type, ['depreciation', 'amortization'], true) || ! $asset->service_start_date || ! $asset->useful_life_months) {
            return;
        }
        if ($asset->schedules()->where('status', 'posted')->exists()) {
            return;
        }

        $asset->schedules()->delete();
        $basis = max(0, (float) $asset->depreciable_basis);
        $months = max(1, (int) $asset->useful_life_months);
        $monthly = round($basis / $months, 2);
        $running = 0.0;
        $period = Carbon::parse((string) $asset->service_start_date)->addMonthNoOverflow()->startOfMonth();
        $rows = [];
        for ($i = 1; $i <= $months; $i++) {
            $amount = $i === $months ? round($basis - $running, 2) : $monthly;
            $running += $amount;
            $rows[] = [
                'period_year' => (int) $period->year,
                'period_month' => (int) $period->month,
                'period' => $period->format('Y-m'),
                'depreciation_amount' => $amount,
                'accumulated_depreciation_after' => round($running, 2),
                'net_book_value_after' => round((float) $asset->acquisition_cost - $running, 2),
                'status' => 'scheduled',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $period->addMonthNoOverflow();
        }
        $asset->schedules()->createMany($rows);
    }

    private function lockSourceVendorBillLine(FixedAsset $asset, array $data): ?VendorBillLine
    {
        $sourceType = (string) ($data['source_type'] ?? $asset->source_type ?? '');
        $sourceLineId = $data['source_line_id'] ?? null;

        if (! in_array($sourceType, ['vendor_bill', 'purchase_invoice'], true) || ! $sourceLineId) {
            return null;
        }

        $line = VendorBillLine::query()->lockForUpdate()->find((int) $sourceLineId);
        if (! $line) {
            throw ApiException::make('SOURCE_VENDOR_BILL_LINE_NOT_FOUND', 'Source vendor bill line was not found.', 422);
        }
        if ((string) $line->line_classification !== 'fixed_asset') {
            throw ApiException::make('SOURCE_LINE_NOT_FIXED_ASSET', 'Source vendor bill line is not classified as fixed asset.', 422);
        }

        return $line;
    }

    private function transaction(FixedAsset $asset, string $type, string $date, float $amount, ?float $quantity = null, array $extra = []): void
    {
        $asset->transactions()->create([
            'transaction_type' => $type,
            'transaction_date' => $date,
            'period' => $extra['period'] ?? Carbon::parse($date)->format('Y-m'),
            'amount' => $amount,
            'quantity' => $quantity,
            'source_type' => $extra['source_type'] ?? null,
            'source_id' => $extra['source_id'] ?? null,
            'journal_entry_id' => $extra['journal_entry_id'] ?? null,
            'metadata' => $extra['metadata'] ?? null,
        ]);
    }

    private function journal(string $date, string $description, string $sourceType, int $sourceId, string $sourceNumber, array $lines): JournalEntry
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);

        $journal = JournalEntry::query()->create([
            'journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, $date),
            'journal_date' => $date,
            'description' => $description,
            'status' => 'posted',
            'revision_no' => 1,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_number' => $sourceNumber,
            'source_revision' => 1,
            'source_module' => 'fixed_assets',
            'is_system_generated' => true,
            'created_by' => auth()->id(),
            'posted_by' => auth()->id(),
            'posted_at' => now(),
        ]);
        $journal->lines()->createMany($lines);
        return $journal->refresh();
    }

    private function assetAccount(FixedAsset $asset): int
    {
        return (int) ($asset->category?->asset_account_id ?: $this->mapping('fixed_assets.cost', ['asset']));
    }

    private function clearingAccount(FixedAsset $asset): int
    {
        return (int) ($asset->category?->clearing_account_id ?: $this->mapping('fixed_assets.clearing', ['asset']));
    }

    private function accumulatedAccount(FixedAsset $asset): int
    {
        $key = $asset->depreciation_type === 'amortization' ? 'fixed_assets.accumulated_amortization' : 'fixed_assets.accumulated_depreciation';
        return (int) ($asset->category?->accumulated_depreciation_account_id ?: $this->mapping($key, ['asset']));
    }

    private function expenseAccount(FixedAsset $asset): int
    {
        $key = $asset->depreciation_type === 'amortization' ? 'fixed_assets.amortization_expense' : 'fixed_assets.depreciation_expense';
        return (int) ($asset->category?->depreciation_expense_account_id ?: $this->mapping($key, ['expense']));
    }

    private function gainAccount(FixedAsset $asset): int
    {
        return (int) ($asset->category?->disposal_gain_account_id ?: $this->mapping('fixed_assets.disposal_gain', ['revenue']));
    }

    private function lossAccount(FixedAsset $asset): int
    {
        return (int) ($asset->category?->disposal_loss_account_id ?: $this->mapping('fixed_assets.disposal_loss', ['expense']));
    }

    private function mapping(string $key, array $types): int
    {
        $mapping = AccountMapping::query()->where('mapping_key', $key)->where('is_active', true)->first();
        if (! $mapping?->account_id) {
            throw ApiException::make('ACCOUNT_MAPPING_MISSING', "Account mapping [{$key}] is required.", 422);
        }
        $account = \App\Models\Tenant\ChartOfAccount::query()
            ->whereKey((int) $mapping->account_id)
            ->whereIn('account_type', $types)
            ->where('is_active', true)
            ->first();
        if (! $account) {
            throw ApiException::make('ACCOUNT_MAPPING_INVALID', "Account mapping [{$key}] is invalid.", 422);
        }
        return (int) $account->id;
    }

    private function audit(string $event, FixedAsset $asset, string $message, array $metadata = []): void
    {
        $this->auditLogService->logSuccess([
            'event' => $event,
            'module' => 'fixed_assets',
            'action' => $event,
            'message' => $message,
            'record_type' => 'fixed_asset',
            'record_id' => $asset->id,
            'record_number' => $asset->asset_number,
            'metadata' => $metadata,
        ], tenant: true);
    }
}
