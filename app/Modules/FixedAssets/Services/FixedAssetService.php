<?php

namespace App\Modules\FixedAssets\Services;

use App\Modules\Budget\Services\BudgetWarningService;
use App\Modules\Budget\Support\CollectsBudgetWarnings;
use App\Modules\FixedAssets\Models\FixedAsset;
use App\Modules\FixedAssets\Models\FixedAssetCategory;
use App\Modules\FixedAssets\Models\FixedAssetDepreciationRun;
use App\Modules\FixedAssets\Models\FixedAssetDepreciationSchedule;
use App\Modules\FixedAssets\Support\OpeningAccumulatedDepreciation;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\Purchase\Models\VendorBillLine;
use App\Shared\Audit\AuditLogService;
use App\Shared\DocumentNumbering\DocumentNumberService;
use App\Shared\DocumentNumbering\DocumentType;
use App\Shared\Exceptions\ApiException;
use App\Shared\Tenant\TenantContext;
use App\Shared\TransactionLifecycle\TransactionDateGuardService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FixedAssetService
{
    use CollectsBudgetWarnings;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly AuditLogService $auditLogService,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly BudgetWarningService $budgetWarning,
    ) {}

    public function categories(array $filters = []): Collection
    {
        $query = FixedAssetCategory::query()->with([
            'assetAccount',
            'accumulatedDepreciationAccount',
            'depreciationExpenseAccount',
            'clearingAccount',
            'disposalGainAccount',
            'disposalLossAccount',
        ]);
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
        ]))->load([
            'assetAccount',
            'accumulatedDepreciationAccount',
            'depreciationExpenseAccount',
            'clearingAccount',
            'disposalGainAccount',
            'disposalLossAccount',
        ]);
    }

    public function updateCategory(FixedAssetCategory $category, array $data): FixedAssetCategory
    {
        $category->fill($data)->save();

        return $category->refresh()->load([
            'assetAccount',
            'accumulatedDepreciationAccount',
            'depreciationExpenseAccount',
            'clearingAccount',
            'disposalGainAccount',
            'disposalLossAccount',
        ]);
    }

    public function list(array $filters = []): Collection
    {
        $query = FixedAsset::query()->with('category', 'department', 'project');
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['category_id'])) {
            $query->where('fixed_asset_category_id', (int) $filters['category_id']);
        }
        if (! empty($filters['asset_class'])) {
            $query->where('asset_class', (string) $filters['asset_class']);
        }

        return $query->orderBy('asset_number')->orderByDesc('id')->get();
    }

    public function find(int $id): FixedAsset
    {
        return FixedAsset::query()
            ->with(
                'category',
                'department',
                'project',
                'acquisitions.journalEntry',
                'schedules.journalEntry',
                'disposals.journalEntry',
                'transactions.journalEntry'
            )
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

    /**
     * Ringkasan aset tetap awal untuk batch saldo awal, dipecah per akun kontrol.
     *
     * Dipakai `OpeningBalanceBatchService` membangun baris sistem. Pemecahan per
     * akun ada DI SINI, bukan di modul Saldo Awal, karena akun mana yang dipakai
     * sebuah aset adalah pengetahuan modul ini (kategori dulu, mapping generik
     * sebagai cadangan) -- menyalinnya ke modul lain berarti dua tempat yang
     * pasti lepas sinkron.
     *
     * `$batchId` null berarti "aset yang belum dibukukan batch mana pun".
     *
     * @return array{count:int, cost:float, accumulated_depreciation:float, net_book_value:float, cost_by_account:array<int,float>, accumulated_by_account:array<int,float>}
     */
    public function openingAssetTotals(?int $batchId = null, ?string $openingDate = null): array
    {
        $empty = [
            'count' => 0,
            'cost' => 0.0,
            'accumulated_depreciation' => 0.0,
            'net_book_value' => 0.0,
            'cost_by_account' => [],
            'accumulated_by_account' => [],
        ];

        if (! Schema::connection('tenant')->hasTable('fixed_assets')) {
            return $empty;
        }

        $assets = $this->openingAssetQuery($batchId)->with('category')->get();
        if ($assets->isEmpty()) {
            return $empty;
        }

        $costByAccount = [];
        $accumulatedByAccount = [];
        $cost = 0.0;
        $accumulated = 0.0;

        foreach ($assets as $asset) {
            $assetCost = round((float) $asset->acquisition_cost, 2);
            $assetAccumulated = round($this->effectiveAccumulatedDepreciation($asset, $openingDate), 2);
            $cost += $assetCost;
            $accumulated += $assetAccumulated;

            if ($assetCost > 0) {
                $account = $this->assetAccount($asset);
                $costByAccount[$account] = round(($costByAccount[$account] ?? 0) + $assetCost, 2);
            }
            if ($assetAccumulated > 0) {
                $account = $this->accumulatedAccount($asset);
                $accumulatedByAccount[$account] = round(($accumulatedByAccount[$account] ?? 0) + $assetAccumulated, 2);
            }
        }

        return [
            'count' => $assets->count(),
            'cost' => round($cost, 2),
            'accumulated_depreciation' => round($accumulated, 2),
            'net_book_value' => round($cost - $accumulated, 2),
            'cost_by_account' => $costByAccount,
            'accumulated_by_account' => $accumulatedByAccount,
        ];
    }

    /**
     * Aktifkan aset tetap awal yang dibukukan sebuah batch saldo awal.
     *
     * Dipanggil dari `OpeningBalanceBatchService::post()`, di dalam transaksi
     * yang sama. **Tidak memposting jurnal apa pun** -- harga perolehan dan
     * akumulasi penyusutannya sudah masuk buku besar lewat baris sistem batch
     * itu. Memanggil `capitalize()` di sini akan membukukannya dua kali; lihat
     * penjaga di method tersebut.
     *
     * @return int jumlah aset yang diaktifkan
     */
    public function activateOpeningAssets(int $batchId, string $openingDate, ?int $journalEntryId = null): int
    {
        if (! Schema::connection('tenant')->hasTable('fixed_assets')) {
            return 0;
        }

        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        $assets = $this->openingAssetQuery(null)->with('category')->get();
        // Wajib SEBELUM pemeriksaan di bawah: aset yang akumulasinya dihitung
        // sistem masih bernilai 0 di titik ini, dan `assertOpeningAssetsDepreciable()`
        // membaca angka itu untuk memutuskan masih ada nilai buku atau tidak.
        $this->fillAutoAccumulatedDepreciation($assets, $openingDate);
        $this->assertOpeningAssetsDepreciable($assets, $openingDate);
        $activated = 0;

        foreach ($assets as $asset) {
            $assetNumber = $asset->asset_number ?: $this->documentNumberService->generate($company, DocumentType::FIXED_ASSET, $openingDate);

            $asset->forceFill([
                'asset_number' => $assetNumber,
                'opening_balance_batch_id' => $batchId,
                'capitalized_at' => Carbon::parse($openingDate),
                'status' => 'active',
            ])->save();

            $this->generateOpeningSchedules($asset->refresh(), $openingDate);
            $this->syncLifecycleStatus($asset->refresh());

            $this->transaction($asset, 'opening_import', $openingDate, (float) $asset->acquisition_cost, (float) $asset->quantity, [
                'source_type' => 'opening_import',
                'source_id' => $batchId,
                'journal_entry_id' => $journalEntryId,
                'metadata' => ['accumulated_depreciation_at_opening' => (float) $asset->accumulated_depreciation],
            ]);
            $this->audit('fixed_asset.opening_activated', $asset, 'Opening fixed asset activated by opening balance posting.', [
                'opening_balance_batch_id' => $batchId,
                'journal_entry_id' => $journalEntryId,
            ]);
            $activated++;
        }

        return $activated;
    }

    /**
     * Akumulasi penyusutan yang BERLAKU untuk sebuah aset saldo awal.
     *
     * Untuk aset yang akumulasinya dititipkan ke sistem, angka tersimpannya
     * masih 0 sampai batch saldo awalnya diposting — sementara pratinjau
     * neraca pembuka sudah harus memperlihatkan angka yang benar. Tanpa ini
     * user menyusun baris penyeimbang ekuitas dari total yang salah, lalu
     * angkanya berubah sendiri saat posting.
     */
    private function effectiveAccumulatedDepreciation(FixedAsset $asset, ?string $openingDate): float
    {
        if ($openingDate === null) {
            return (float) $asset->accumulated_depreciation;
        }

        return $this->autoAccumulatedDepreciation($asset, $openingDate)
            ?? (float) $asset->accumulated_depreciation;
    }

    /**
     * Estimasi untuk aset yang ditandai hitung-otomatis, atau null kalau aset
     * ini bukan salah satunya (atau kategorinya memang tidak menyusut).
     */
    private function autoAccumulatedDepreciation(FixedAsset $asset, string $openingDate): ?float
    {
        if (($asset->metadata['accumulated_depreciation_auto'] ?? false) !== true) {
            return null;
        }

        return OpeningAccumulatedDepreciation::estimate(
            (float) $asset->acquisition_cost,
            (float) $asset->salvage_value,
            $asset->useful_life_years ? (int) $asset->useful_life_years : null,
            $asset->service_start_date?->toDateString(),
            $openingDate,
        );
    }

    /**
     * Isi akumulasi penyusutan aset saldo awal yang dikosongkan user.
     *
     * Baru bisa dikerjakan di sini, bukan saat impor atau saat form disimpan:
     * angkanya dinyatakan PER TANGGAL SALDO AWAL, dan tanggal itu baru pasti
     * ketika batchnya diposting. Alasan yang sama dipakai
     * `assertOpeningAssetsDepreciable()` di bawah.
     *
     * Penandanya sengaja tidak dihapus setelah dihitung: reopen mengembalikan
     * aset ke draft, dan batch berikutnya bisa bertanggal lain — saat itu
     * angkanya harus dihitung ulang, bukan memakai sisa hitungan lama.
     *
     * @param  \Illuminate\Support\Collection<int, FixedAsset>  $assets
     */
    private function fillAutoAccumulatedDepreciation($assets, string $openingDate): void
    {
        foreach ($assets as $asset) {
            $estimate = $this->autoAccumulatedDepreciation($asset, $openingDate);

            // Kategori tanpa penyusutan (tanah, aset dalam penyelesaian) tidak
            // punya estimasi. Nol memang jawaban yang benar untuk mereka.
            if ($estimate === null) {
                continue;
            }

            $metadata = (array) ($asset->metadata ?? []);
            $metadata['accumulated_depreciation_auto_computed'] = $estimate;

            $asset->forceFill([
                'accumulated_depreciation' => $estimate,
                'net_book_value' => round((float) $asset->acquisition_cost - $estimate, 2),
                'metadata' => $metadata,
            ])->save();
        }
    }

    /**
     * Tolak aset yang masa manfaatnya sudah habis SEBELUM tanggal saldo awal
     * tapi masih membawa nilai buku.
     *
     * Itu ketidakcocokan di data klien, bukan kasus yang bisa diputuskan
     * sistem: tidak ada bulan tersisa untuk menyusutkan sisanya. Kalau
     * dibiarkan, asetnya lolos tanpa jadwal lalu ditandai `fully_depreciated`,
     * dan sisa nilainya diam-diam tidak pernah dibebankan.
     *
     * Diperiksa di sini, bukan saat impor, karena tanggal saldo awal baru pasti
     * di titik ini — dan karena aset juga bisa masuk lewat form manual, bukan
     * hanya lewat impor.
     *
     * @param  \Illuminate\Support\Collection<int, FixedAsset>  $assets
     */
    private function assertOpeningAssetsDepreciable($assets, string $openingDate): void
    {
        $openingMonth = Carbon::parse($openingDate)->startOfMonth();
        $offenders = [];

        foreach ($assets as $asset) {
            if (! in_array((string) $asset->depreciation_type, ['depreciation', 'amortization'], true)) {
                continue;
            }
            $remaining = round((float) $asset->depreciable_basis - (float) $asset->accumulated_depreciation, 2);
            if ($remaining <= 0 || ! $asset->last_depreciation_period) {
                continue;
            }
            if (Carbon::createFromFormat('Y-m', (string) $asset->last_depreciation_period)->startOfMonth()->lt($openingMonth)) {
                $offenders[] = $asset->name.' (masa manfaat berakhir '.$asset->last_depreciation_period.', sisa nilai '.number_format($remaining, 2, ',', '.').')';
            }
        }

        if ($offenders !== []) {
            throw ApiException::make(
                'OPENING_ASSET_LIFE_ALREADY_ENDED',
                'Ada aset saldo awal yang masa manfaatnya sudah berakhir sebelum tanggal saldo awal tapi masih punya nilai buku. Perbaiki akumulasi penyusutan atau masa manfaatnya lebih dulu: '.implode('; ', $offenders),
                422,
                ['fixed_assets' => $offenders]
            );
        }
    }

    /**
     * Kebalikan `activateOpeningAssets()`, dipakai saat batch saldo awal dibuka
     * kembali (reopen). Reopen membatalkan jurnal pembuka, jadi aset yang
     * dibukukannya harus ikut dikembalikan ke draft -- kalau tidak, register
     * menyatakan aset itu ada sementara buku besarnya sudah tidak.
     *
     * @return int jumlah aset yang dikembalikan ke draft
     */
    public function deactivateOpeningAssets(int $batchId): int
    {
        if (! Schema::connection('tenant')->hasTable('fixed_assets')) {
            return 0;
        }

        $assets = $this->openingAssetQuery($batchId)->get();
        $reverted = 0;

        foreach ($assets as $asset) {
            if ($asset->schedules()->where('status', 'posted')->exists()) {
                throw ApiException::make(
                    'FIXED_ASSET_HAS_POSTED_DEPRECIATION',
                    "Aset {$asset->asset_number} sudah punya penyusutan terposting; saldo awal tidak bisa dibuka kembali.",
                    422
                );
            }

            $asset->schedules()->delete();
            $asset->transactions()->where('transaction_type', 'opening_import')->delete();
            $asset->forceFill([
                'opening_balance_batch_id' => null,
                'capitalized_at' => null,
                'status' => 'draft',
            ])->save();

            $this->audit('fixed_asset.opening_deactivated', $asset, 'Opening fixed asset reverted to draft after opening balance reopen.', [
                'opening_balance_batch_id' => $batchId,
            ]);
            $reverted++;
        }

        return $reverted;
    }

    /**
     * @return Builder<FixedAsset>
     */
    private function openingAssetQuery(?int $batchId)
    {
        $query = FixedAsset::query()->where('source_type', 'opening_import');

        return $batchId === null
            ? $query->whereNull('opening_balance_batch_id')
            : $query->where('opening_balance_batch_id', $batchId);
    }

    public function update(FixedAsset $asset, array $data): FixedAsset
    {
        if (FixedAssetDepreciationSchedule::query()->where('fixed_asset_id', $asset->id)->where('status', 'posted')->exists()) {
            throw ApiException::make('FIXED_ASSET_HAS_POSTED_DEPRECIATION', 'Asset with posted depreciation cannot be edited.', 422);
        }
        if (in_array((string) $asset->status, ['disposed', 'partially_disposed'], true)) {
            throw ApiException::make('FIXED_ASSET_NOT_EDITABLE', 'Disposed asset cannot be edited.', 422);
        }
        if ($this->hasLockedFinancialChanges($asset, $data)) {
            throw ApiException::make('FIXED_ASSET_FINANCIAL_FIELDS_LOCKED', 'Financial fields are locked after capitalization.', 422);
        }

        $category = isset($data['fixed_asset_category_id'])
            ? FixedAssetCategory::query()->findOrFail((int) $data['fixed_asset_category_id'])
            : $asset->category;

        return DB::connection('tenant')->transaction(function () use ($asset, $data, $category) {
            $merged = array_merge($asset->toArray(), $data);

            // Aset yang akumulasinya dititipkan ke sistem harus tetap begitu
            // walau yang diubah cuma namanya: tanpa baris ini `array_merge`
            // menyuntikkan nilai tersimpan (masih 0 selama aset draft) sebagai
            // kalau-kalau user yang mengetiknya, dan penandanya ikut hilang.
            if (! array_key_exists('accumulated_depreciation', $data)
                && (($asset->metadata['accumulated_depreciation_auto'] ?? false) === true)) {
                unset($merged['accumulated_depreciation']);
            }

            $asset->fill($this->assetPayload($merged, $category, preserveStatus: true))->save();
            if ($asset->capitalized_at) {
                $this->generateSchedules($asset->refresh());
            }
            $this->audit('fixed_asset.updated', $asset, 'Fixed asset updated.');

            return $asset->refresh()->load('category');
        });
    }

    public function capitalize(FixedAsset $asset, array $data): FixedAsset
    {
        // Aset saldo awal TIDAK boleh lewat sini. Harga perolehannya sudah
        // dibukukan jurnal saldo awal lewat baris sistem batch; kapitalisasi
        // normal akan memposting Dr Aset / Cr Kliring sekali lagi -- nilai
        // asetnya dobel dan saldo kliring menggantung tanpa lawan. Aset ini
        // diaktifkan otomatis oleh `activateOpeningAssets()` saat batch saldo
        // awalnya diposting.
        if ((string) $asset->source_type === 'opening_import') {
            throw ApiException::make(
                'FIXED_ASSET_OPENING_NOT_CAPITALIZABLE',
                'Aset saldo awal tidak dikapitalisasi manual. Aset ini aktif otomatis saat batch saldo awalnya diposting.',
                422
            );
        }

        if (in_array((string) $asset->status, ['active', 'capitalized', 'partially_disposed', 'disposed'], true)) {
            throw ApiException::make('FIXED_ASSET_ALREADY_CAPITALIZED', 'Asset is already capitalized or disposed.', 422);
        }

        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        $date = (string) ($data['capitalization_date'] ?? $asset->acquisition_date?->toDateString());
        $this->guardDate($date, 'post');
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
        if (! in_array((string) $asset->status, ['active', 'capitalized', 'partially_disposed', 'fully_depreciated'], true)) {
            throw ApiException::make('FIXED_ASSET_NOT_DISPOSABLE', 'Only capitalized, active, or fully depreciated assets can be disposed.', 422);
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
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        $this->guardDate((string) $data['disposal_date'], 'post');

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

            if ($newRemainingQty <= 0) {
                $asset->schedules()->where('status', 'scheduled')->where('period', '>=', $period)->delete();
            }

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
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

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
            $budgetWarnings = [];
            if ($schedules->isNotEmpty() && (float) $schedules->sum('depreciation_amount') > 0) {
                $grouped = [];
                // Baris per skedul, sebelum digabung per akun — Gap B. Batch ini
                // menjumlahkan SEMUA aset ke satu baris jurnal per akun beban
                // (lihat pembentukan $lines di bawah), sehingga department_id/
                // project_id tiap aset hilang tepat di jurnalnya. Peringatan
                // budget dihitung DI SINI, per skedul, sebelum peleburan itu
                // terjadi — bukan dibaca ulang dari jurnal yang sudah kehilangan
                // dimensinya.
                foreach ($schedules as $schedule) {
                    $asset = $schedule->asset;
                    if (! $asset) {
                        continue;
                    }
                    $expense = $this->expenseAccount($asset);
                    $accumulated = $this->accumulatedAccount($asset);
                    $grouped['dr_'.$expense] = ($grouped['dr_'.$expense] ?? 0) + (float) $schedule->depreciation_amount;
                    $grouped['cr_'.$accumulated] = ($grouped['cr_'.$accumulated] ?? 0) + (float) $schedule->depreciation_amount;

                    $budgetWarnings[] = [
                        'account_id' => $expense,
                        'department_id' => $asset->department_id,
                        'project_id' => $asset->project_id,
                        'amount' => (float) $schedule->depreciation_amount,
                    ];
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

            // Disimpan di `metadata`, bukan dikembalikan lewat response terpisah:
            // proses ini adalah batch periode-akhir (dipanggil `PeriodEndService`),
            // bukan satu dokumen yang langsung dilihat user lewat satu request
            // interaktif seperti Journal/Cash Payment/Sales Invoice/Purchase
            // Order/Stock Movement. Bentuk tiap item warning tetap identik
            // dengan `meta.warnings` di keempat modul itu — hanya wadahnya beda,
            // supaya frontend bisa merender dengan cara yang sama begitu
            // `run.metadata.budget_warnings` mulai dibaca.
            $run->update([
                'metadata' => array_merge((array) $run->metadata, [
                    // postHoc: true — jurnal penyusutan (kalau ada) sudah dibuat
                    // di atas sebelum baris ini berjalan, jadi `actual` yang
                    // dibaca `check()` sudah memuat batch ini sendiri. Lihat
                    // `CollectsBudgetWarnings` untuk penjelasan lengkap.
                    'budget_warnings' => $this->collectBudgetWarningsFor(
                        $this->budgetWarning,
                        (int) $company->id,
                        $budgetWarnings,
                        $period,
                        postHoc: true,
                    ),
                ]),
            ]);

            foreach ($schedules as $schedule) {
                $asset = $schedule->asset;
                if (! $asset) {
                    continue;
                }

                if ($this->isDisposedBeforeOrInPeriod($asset, $period)) {
                    continue;
                }

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

                $this->syncLifecycleStatus($asset->refresh());
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
        $salvageCapped = min($salvage, $cost);
        $lifeYears = in_array($category->depreciation_type, ['depreciation', 'amortization'], true)
            ? (int) ($data['useful_life_years'] ?? $category->default_useful_life_years ?? 4)
            : null;
        $serviceStart = $data['service_start_date'] ?? null;
        $firstPeriod = $serviceStart && $lifeYears ? Carbon::parse((string) $serviceStart)->addMonthNoOverflow()->format('Y-m') : null;
        $lastPeriod = $firstPeriod && $lifeYears ? Carbon::createFromFormat('Y-m', $firstPeriod)->addMonthsNoOverflow(($lifeYears * 12) - 1)->format('Y-m') : null;

        // Kolom akumulasi yang dikosongkan pada aset saldo awal TIDAK berarti
        // nol -- aset warisan yang sudah dipakai bertahun-tahun hampir pasti
        // sudah menyusut. Ia berarti "hitungkan": asetnya ditandai di sini dan
        // angkanya diisi `activateOpeningAssets()` saat tanggal saldo awal
        // sudah pasti. Nol yang diketik user secara eksplisit tetap nol.
        $autoAccumulated = $this->wantsAutoAccumulatedDepreciation($data);
        $accumulated = $autoAccumulated ? 0.0 : round((float) ($data['accumulated_depreciation'] ?? 0), 2);
        $depreciableBasis = max(0, round($cost - $salvageCapped, 2));
        // Aset warisan yang diimpor sebagai saldo awal membawa akumulasi
        // penyusutannya sendiri. Tanpa batas ini, salah ketik satu digit
        // menghasilkan nilai buku negatif yang baru ketahuan saat neraca
        // saldo awal tidak balance -- jauh dari penyebabnya.
        if ($accumulated > $depreciableBasis) {
            throw ApiException::make(
                'FIXED_ASSET_ACCUMULATED_EXCEEDS_BASIS',
                'Accumulated depreciation cannot exceed acquisition cost minus salvage value.',
                422,
                ['accumulated_depreciation' => ['Akumulasi penyusutan tidak boleh melebihi harga perolehan dikurangi nilai residu.']]
            );
        }

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
            'salvage_value' => $salvageCapped,
            'depreciable_basis' => $depreciableBasis,
            'accumulated_depreciation' => $accumulated,
            'net_book_value' => round($cost - $accumulated, 2),
            'department_id' => $data['department_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'metadata' => $this->withAutoAccumulatedFlag($data['metadata'] ?? null, $autoAccumulated),
        ];
    }

    /**
     * Aset saldo awal yang datang TANPA angka akumulasi penyusutan sama sekali
     * (kunci tidak ada, atau null) minta dihitungkan sistem. Angka nol yang
     * diketik user bukan permintaan itu -- ada aset warisan yang memang belum
     * pernah disusutkan, dan menimpanya akan salah.
     *
     * @param  array<string, mixed>  $data
     */
    private function wantsAutoAccumulatedDepreciation(array $data): bool
    {
        return ($data['source_type'] ?? null) === 'opening_import'
            && ! isset($data['accumulated_depreciation']);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    private function withAutoAccumulatedFlag(?array $metadata, bool $auto): ?array
    {
        $metadata ??= [];

        if ($auto) {
            $metadata['accumulated_depreciation_auto'] = true;
        } else {
            unset($metadata['accumulated_depreciation_auto'], $metadata['accumulated_depreciation_auto_computed']);
        }

        return $metadata === [] ? null : $metadata;
    }

    private function guardDate(string $date, string $action = 'post'): void
    {
        $check = $this->dateGuardService->check($date, $action, 'fixed_assets');
        if ($check->denied()) {
            $arr = $check->toArray();
            throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']);
        }
    }

    private function hasLockedFinancialChanges(FixedAsset $asset, array $data): bool
    {
        if ((string) $asset->status === 'draft') {
            return false;
        }

        foreach ($this->lockedFinancialFields() as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            if ($this->normalizeFinancialField($field, $asset->{$field} ?? null) !== $this->normalizeFinancialField($field, $data[$field])) {
                return true;
            }
        }

        return false;
    }

    private function lockedFinancialFields(): array
    {
        return [
            'fixed_asset_category_id',
            'acquisition_date',
            'acquisition_cost',
            'quantity',
            'service_start_date',
            'useful_life_years',
            'salvage_value',
        ];
    }

    private function normalizeFinancialField(string $field, mixed $value): string
    {
        return match ($field) {
            'fixed_asset_category_id', 'useful_life_years' => (string) (int) ($value ?? 0),
            'acquisition_date', 'service_start_date' => $value ? Carbon::parse((string) $value)->format('Y-m-d') : '',
            'quantity' => number_format((float) ($value ?? 0), 4, '.', ''),
            'acquisition_cost', 'salvage_value' => number_format((float) ($value ?? 0), 2, '.', ''),
            default => (string) ($value ?? ''),
        };
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

    /**
     * Jadwal penyusutan untuk aset tetap awal — SISA nilai selama SISA umur.
     *
     * Berbeda dari `generateSchedules()` yang mengasumsikan aset baru dan selalu
     * mulai dari nol. Aset saldo awal sudah menyusut di pembukuan sebelumnya,
     * jadi:
     *
     *   - Periode pertama = bulan tanggal saldo awal (BUKAN +1 bulan seperti
     *     aset baru). Akumulasi dari pengguna dihitung sampai sehari sebelum
     *     tanggal itu, jadi bulan tersebut memang belum tersusut.
     *   - Periode terakhir = `last_depreciation_period` aset itu, yang dihitung
     *     dari tanggal mulai pakai ASLI. Akhir masa manfaat adalah fakta
     *     kalender dan tidak boleh bergeser karena perusahaannya ganti aplikasi.
     *   - Nilai yang dijadwalkan = basis - akumulasi. Dibagi rata ke sisa bulan,
     *     jadi kalau angka akumulasi dari klien tidak persis garis lurus
     *     (mis. sistem lamanya memakai metode lain), penyesuaiannya terserap
     *     otomatis dan asetnya tetap habis tepat di akhir masa manfaat.
     *
     * Tanpa jadwal (umur sudah habis, atau tidak menyusut sama sekali) aset
     * dibiarkan tanpa baris — `syncLifecycleStatus()` yang menandainya
     * `fully_depreciated`.
     */
    private function generateOpeningSchedules(FixedAsset $asset, string $openingDate): void
    {
        if (! in_array((string) $asset->depreciation_type, ['depreciation', 'amortization'], true)) {
            return;
        }
        if ($asset->schedules()->where('status', 'posted')->exists()) {
            return;
        }

        $asset->schedules()->delete();

        $remaining = round((float) $asset->depreciable_basis - (float) $asset->accumulated_depreciation, 2);
        if ($remaining <= 0 || ! $asset->last_depreciation_period) {
            return;
        }

        $first = Carbon::parse($openingDate)->startOfMonth();
        $last = Carbon::createFromFormat('Y-m', (string) $asset->last_depreciation_period)->startOfMonth();
        if ($last->lt($first)) {
            return;
        }

        $months = ((int) $first->diffInMonths($last)) + 1;
        $monthly = round($remaining / $months, 2);
        $running = (float) $asset->accumulated_depreciation;
        $period = $first->copy();
        $rows = [];

        for ($i = 1; $i <= $months; $i++) {
            $amount = $i === $months
                ? round((float) $asset->depreciable_basis - $running, 2)
                : $monthly;
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

    private function syncLifecycleStatus(FixedAsset $asset): void
    {
        if ($asset->disposed_at || ! in_array((string) $asset->status, ['active', 'capitalized', 'partially_disposed'], true)) {
            return;
        }

        if ($asset->schedules()->where('status', 'scheduled')->exists()) {
            return;
        }

        $asset->forceFill(['status' => 'fully_depreciated'])->save();
    }

    private function isDisposedBeforeOrInPeriod(FixedAsset $asset, string $period): bool
    {
        if (! $asset->disposed_at) {
            return false;
        }

        return Carbon::parse((string) $asset->disposed_at)->format('Y-m') <= $period;
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
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

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

    /**
     * Akun harga perolehan sebuah aset.
     *
     * Fallback `fixed_assets.cost` menunjuk akun Peralatan -- akun untuk aset
     * yang DISUSUTKAN. Untuk kategori yang tidak pernah disusutkan (Tanah, Aset
     * Dalam Penyelesaian, Goodwill) fallback itu bukan sekadar kurang tepat, ia
     * salah kelas: nilai tanah mendarat di baris Peralatan pada neraca, dan
     * satu-satunya cara ketahuan adalah ada orang membaca neraca lalu curiga
     * kenapa Peralatan sebesar itu.
     *
     * Penjagaannya diletakkan di sini, bukan di validasi impor, karena di sini
     * SELURUH jalur bertemu: impor saldo awal, form aset manual, kapitalisasi
     * dari tagihan vendor, pelepasan, dan posting saldo awal. Penjagaan di lapis
     * impor saja meninggalkan form manual tetap terbuka.
     */
    private function assetAccount(FixedAsset $asset): int
    {
        $category = $asset->category;

        if ($category?->asset_account_id) {
            return (int) $category->asset_account_id;
        }

        if ($category !== null && ! $this->categoryDepreciates($category)) {
            throw ApiException::make(
                'FIXED_ASSET_CATEGORY_ACCOUNT_REQUIRED',
                sprintf(
                    'Kategori %s tidak disusutkan, jadi ia wajib punya Akun Aktiva sendiri dan tidak boleh memakai akun Peralatan. Buat akunnya di Daftar Akun, lalu petakan di Master Data → Kategori Aset Tetap → kolom Akun Aktiva.',
                    $category->name,
                ),
                422,
                ['fixed_asset_category_id' => [(int) $category->id]],
            );
        }

        return (int) $this->mapping('fixed_assets.cost', ['asset']);
    }

    /**
     * Kategori ini menghasilkan penyusutan/amortisasi atau tidak.
     *
     * Satu-satunya pembeda yang dipakai penjagaan akun lintas kelas. Sengaja
     * memakai daftar positif, bukan menyenaraikan kode kategori: kategori buatan
     * user yang `depreciation_type`-nya `none` atau `impairment_only` ikut
     * terjaga tanpa perlu didaftarkan di mana pun.
     */
    private function categoryDepreciates(FixedAssetCategory $category): bool
    {
        return in_array((string) $category->depreciation_type, ['depreciation', 'amortization'], true);
    }

    private function clearingAccount(FixedAsset $asset): int
    {
        return (int) ($asset->category?->clearing_account_id ?: $this->mapping('fixed_assets.clearing', ['asset']));
    }

    /**
     * Fallback penyusutan memakai kunci kelas Peralatan, bukan kunci generik:
     * `fixed_assets.accumulated_depreciation` / `.depreciation_expense` sudah
     * dihapus karena menduplikasi field per kelas di halaman Pemetaan Akun.
     * Akun defaultnya identik (1531 / 6172), jadi jurnal kategori yang kolom
     * akunnya masih null tetap jatuh ke akun yang sama seperti sebelumnya.
     */
    private function accumulatedAccount(FixedAsset $asset): int
    {
        $key = $asset->depreciation_type === 'amortization'
            ? 'fixed_assets.accumulated_amortization'
            : 'fixed_assets.equipment_accumulated_depreciation';

        $category = $asset->category;

        if ($category?->accumulated_depreciation_account_id) {
            return (int) $category->accumulated_depreciation_account_id;
        }

        // Sisi kedua dari penjagaan yang sama. Kategori non-penyusutan tidak
        // punya akun akumulasi dan memang tidak seharusnya punya, jadi fallback
        // di sini akan membukukan "akumulasi penyusutan tanah" ke Akumulasi
        // Penyusutan PERALATAN. Jalur ini hanya tercapai kalau asetnya benar-
        // benar membawa akumulasi (seluruh pemanggil bergerbang `> 0`), dan
        // akumulasi pada aset yang tidak disusutkan itu sendiri sudah keliru.
        if ($category !== null && ! $this->categoryDepreciates($category)) {
            throw ApiException::make(
                'FIXED_ASSET_CATEGORY_ACCOUNT_REQUIRED',
                sprintf(
                    'Aset %s berkategori %s yang tidak disusutkan, tapi membawa akumulasi penyusutan. Angka itu tidak bisa dibukukan ke akun Akumulasi Penyusutan Peralatan — kosongkan akumulasinya, atau pindahkan aset ini ke kategori yang memang disusutkan.',
                    $asset->name,
                    $category->name,
                ),
                422,
                ['fixed_asset_category_id' => [(int) $category->id]],
            );
        }

        return (int) $this->mapping($key, ['asset']);
    }

    private function expenseAccount(FixedAsset $asset): int
    {
        $key = $asset->depreciation_type === 'amortization'
            ? 'fixed_assets.amortization_expense'
            : 'fixed_assets.equipment_depreciation_expense';

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
        $account = ChartOfAccount::query()
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
