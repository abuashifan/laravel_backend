<?php

namespace App\Modules\Imports\Services\Committers;

use App\Modules\FixedAssets\Models\FixedAsset;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\OpeningBalance\Models\OpeningBalanceBatch;
use App\Modules\OpeningBalance\Services\OpeningBalanceBatchService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\CompanyModuleSetting;
use App\Shared\Models\CompanySetupState;
use App\Shared\Models\FiscalYear;
use App\Shared\Tenant\TenantContext;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Profil impor saldo awal — Fase 7.
 *
 * Mengisi baris draft batch saldo awal dari berkas neraca saldo klien.
 * **Tidak** memvalidasi dan **tidak** memposting: user tetap menekan Validasi →
 * Posting sendiri di halaman Saldo Awal. Itu keputusan draft-only yang berlaku
 * di seluruh rencana impor (lihat `Finlite_knowladge/plans/data-import/README.md`)
 * — impor mengisi, manusia yang memutuskan.
 *
 * ── Kenapa akun aset tetap ditolak di sini ──────────────────────────────────
 *
 * `OpeningBalanceBatchService::fixedAssetSystemLines()` sudah menghasilkan baris
 * harga perolehan dan akumulasi penyusutan SECARA OTOMATIS dari tabel
 * `fixed_assets` yang `source_type = 'opening_import'`. Baris manual dengan akun
 * yang sama ditolak batch-nya sendiri lewat `FIXED_ASSET_CONTROL_DUPLICATE`.
 *
 * Menolaknya di sini, per baris, memindahkan kegagalan itu dari "batch tidak
 * bisa divalidasi dengan pesan yang tidak menyebut baris mana" ke "baris ke-17
 * salah, dan ini alasannya".
 *
 * ── Urutan wajib: aset tetap awal DULU ──────────────────────────────────────
 *
 * Konsekuensi langsung dari paragraf di atas: selama modul Aktiva Tetap aktif
 * dan aset tetap awalnya belum diimpor (atau belum dinyatakan tidak ada),
 * seluruh berkas saldo awal ditolak lewat `openingFixedAssetsPrecondition()`.
 * Aturan yang sama sudah tertulis di `config/imports.php` pada profil
 * `fixed_asset_opening`; di sini ia ditegakkan, bukan sekadar didokumentasikan.
 */
class OpeningBalanceImportCommitter implements ImportProfileCommitter
{
    use Concerns\DetectsDuplicateCodesInBatch;

    /**
     * Kunci mapping yang menghasilkan baris sistem aset tetap. Kunci generik
     * yang tersisa (cost, akumulasi amortisasi) dan seluruh kunci per kelas
     * didaftar: baris sistem memakai akun kategori aset
     * (`config/fixed_asset_categories.php`), sedangkan yang generik masih jadi
     * fallback saat kolom akun kategori kosong. Menolak keduanya membuat
     * berkas tetap divalidasi sama, apa pun jalur akun yang terpakai.
     */
    private const FIXED_ASSET_CONTROL_KEYS = [
        'fixed_assets.cost',
        'fixed_assets.accumulated_amortization',
        'fixed_assets.vehicle_cost',
        'fixed_assets.vehicle_accumulated_depreciation',
        'fixed_assets.building_cost',
        'fixed_assets.building_accumulated_depreciation',
        'fixed_assets.equipment_cost',
        'fixed_assets.equipment_accumulated_depreciation',
        'fixed_assets.software_cost',
        'fixed_assets.software_accumulated_amortization',
    ];

    public function __construct(
        private readonly OpeningBalanceBatchService $openingBalanceBatchService,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, string>  $normalized
     * @return array<string, list<string>>
     */
    public function validateRow(ImportBatch $batch, array $normalized): array
    {
        if ($precondition = $this->preconditionError()) {
            return ['account_code' => [$precondition]];
        }

        $errors = [];

        $accountCode = trim((string) ($normalized['account_code'] ?? ''));
        $debit = trim((string) ($normalized['debit'] ?? ''));
        $credit = trim((string) ($normalized['credit'] ?? ''));

        // ── Account Code ────────────────────────────────────────────────
        if ($accountCode !== '') {
            $account = ChartOfAccount::query()->where('account_code', $accountCode)->first();

            if (! $account instanceof ChartOfAccount) {
                $errors['account_code'][] = "Akun dengan kode '{$accountCode}' tidak ditemukan.";
            } elseif (! $account->is_active) {
                $errors['account_code'][] = "Akun '{$accountCode}' tidak aktif.";
            } elseif ($account->children()->exists()) {
                $errors['account_code'][] = "Akun '{$accountCode}' adalah akun induk. Saldo awal hanya bisa diisi ke akun daun (leaf account).";
            } elseif (in_array((int) $account->id, $this->fixedAssetControlAccountIds(), true)) {
                $errors['account_code'][] = "Akun '{$accountCode}' adalah akun kontrol aset tetap yang dihasilkan otomatis dari data aset tetap awal. Impor asetnya lewat profil Aset Tetap Awal, jangan dimasukkan di berkas ini.";
            } elseif ($this->accountAlreadyInBatch((int) $account->id)) {
                // Keputusan 7B-1: impor MENGGABUNG, tidak mengganti. Baris yang
                // sudah diketik user di halaman Saldo Awal tidak boleh hilang
                // diam-diam, jadi bentrokannya ditandai di sini.
                $errors['account_code'][] = "Akun '{$accountCode}' sudah punya baris saldo awal di batch yang sedang berjalan. Hapus dulu barisnya di halaman Saldo Awal, atau keluarkan baris ini dari berkas.";
            }

            if ($this->isCodeUsedElsewhereInBatch($batch, 'account_code', $accountCode)) {
                $errors['account_code'][] = "Akun '{$accountCode}' muncul lebih dari sekali di berkas ini. Gabungkan jadi satu baris.";
            }
        }

        // ── Debit / Credit ──────────────────────────────────────────────
        $hasDebit = $debit !== '' && is_numeric($debit) && ((float) $debit) > 0;
        $hasCredit = $credit !== '' && is_numeric($credit) && ((float) $credit) > 0;

        if ($debit !== '' && ! is_numeric($debit)) {
            $errors['debit'][] = 'Debit harus berupa angka.';
        } elseif ($debit !== '' && (float) $debit < 0) {
            $errors['debit'][] = 'Debit tidak boleh negatif.';
        }

        if ($credit !== '' && ! is_numeric($credit)) {
            $errors['credit'][] = 'Credit harus berupa angka.';
        } elseif ($credit !== '' && (float) $credit < 0) {
            $errors['credit'][] = 'Credit tidak boleh negatif.';
        }

        if (! $hasDebit && ! $hasCredit) {
            $errors['debit'][] = 'Debit atau Credit harus diisi dengan nilai > 0.';
            $errors['credit'][] = 'Debit atau Credit harus diisi dengan nilai > 0.';
        } elseif ($hasDebit && $hasCredit) {
            $errors['debit'][] = 'Tidak boleh mengisi Debit dan Credit sekaligus dalam satu baris.';
            $errors['credit'][] = 'Tidak boleh mengisi Debit dan Credit sekaligus dalam satu baris.';
        }

        return $errors;
    }

    /**
     * @return array<int, array{status: string, document_id: ?int, document_type: ?string, error: ?string}>
     */
    public function commit(ImportBatch $batch): array
    {
        $results = [];
        $rows = $batch->rows()->where('status', 'valid')->orderBy('row_number')->get();

        if ($rows->isEmpty()) {
            return $results;
        }

        if ($precondition = $this->preconditionError()) {
            return $this->failAll($rows, $precondition);
        }

        try {
            $target = $this->resolveBatch();
        } catch (ApiException $e) {
            return $this->failAll($rows, $e->getMessage());
        } catch (Throwable $e) {
            return $this->failAll($rows, 'Gagal menyiapkan batch saldo awal: '.$e->getMessage());
        }

        // Baca-gabung-tulis: `replaceLines()` MENGHAPUS seluruh baris sebelum
        // menyisipkan yang baru, jadi baris manual yang sudah ada harus dibawa
        // serta. Baris sistem aset tetap tidak ikut — ia dihitung saat preview,
        // tidak pernah tersimpan di tabel.
        $lines = $target->lines()
            ->get()
            ->map(fn ($line): array => [
                'account_id' => (int) $line->account_id,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'description' => $line->description,
                'source_type' => $line->source_type,
                'source_id' => $line->source_id,
                'source_line_id' => $line->source_line_id,
                'metadata' => (array) ($line->metadata ?? []),
            ])
            ->all();

        $committed = [];

        foreach ($rows as $row) {
            $n = (array) ($row->normalized ?? []);
            $accountId = ChartOfAccount::query()->where('account_code', trim((string) ($n['account_code'] ?? '')))->value('id');

            if ($accountId === null) {
                $results[$row->id] = ['status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => 'Akun tidak ditemukan saat commit.'];

                continue;
            }

            $description = trim((string) ($n['description'] ?? ''));
            $lines[] = [
                'account_id' => (int) $accountId,
                'debit' => (float) ($n['debit'] ?? 0),
                'credit' => (float) ($n['credit'] ?? 0),
                'description' => $description !== '' ? $description : 'Saldo awal',
                'source_type' => 'opening_balance_import',
                'metadata' => [
                    'import_batch_uuid' => $batch->uuid,
                    'import_row_number' => (int) $row->row_number,
                ],
            ];
            $committed[] = $row;
        }

        // Satu panggilan untuk seluruh berkas, bukan per baris: `replaceLines()`
        // menulis ulang seluruh isi batch, jadi memanggilnya per baris berarti
        // menulis ulang N kali dan menyisakan batch setengah jadi kalau gagal
        // di tengah.
        try {
            $this->openingBalanceBatchService->replaceLines($target, $lines);
        } catch (ApiException $e) {
            return $this->failAll($rows, $e->getMessage());
        } catch (Throwable $e) {
            return $this->failAll($rows, 'Gagal menulis baris saldo awal: '.$e->getMessage());
        }

        foreach ($committed as $row) {
            $results[$row->id] = [
                'status' => 'committed',
                'document_id' => (int) $target->id,
                'document_type' => OpeningBalanceBatch::class,
                'error' => null,
            ];
        }

        return $results;
    }

    /**
     * Batch draft yang sedang berjalan, atau buat baru kalau belum ada.
     *
     * Keputusan 7B-2 soal `opening_date`: awal tahun fiskal aktif, fallback
     * hari ini. Saldo awal bertanggal hari ini hampir selalu salah — ia harus
     * berdiri di batas periode, bukan di tengah bulan berjalan.
     */
    private function resolveBatch(): OpeningBalanceBatch
    {
        $existing = $this->openingBalanceBatchService->latestActiveBatch();
        if ($existing instanceof OpeningBalanceBatch) {
            return $existing;
        }

        return $this->openingBalanceBatchService->create(['opening_date' => $this->defaultOpeningDate()]);
    }

    private function defaultOpeningDate(): string
    {
        $company = $this->tenantContext->company();

        if ($company) {
            $start = FiscalYear::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->orderByDesc('start_date')
                ->value('start_date');

            if ($start) {
                return $start instanceof \DateTimeInterface
                    ? $start->format('Y-m-d')
                    : (string) $start;
            }
        }

        return now()->toDateString();
    }

    private function preconditionError(): ?string
    {
        if ($fixedAssets = $this->openingFixedAssetsPrecondition()) {
            return $fixedAssets;
        }

        $batch = $this->openingBalanceBatchService->latestActiveBatch();

        if ($batch instanceof OpeningBalanceBatch && $batch->postedOrLocked()) {
            return 'Saldo awal sudah diposting/dikunci (status: '.$batch->status.'). Buka kembali (reopen) dulu di halaman Saldo Awal sebelum mengimpor.';
        }

        if ($batch instanceof OpeningBalanceBatch && ! $batch->editable()) {
            return 'Batch saldo awal yang ada tidak bisa diubah (status: '.$batch->status.').';
        }

        // Terjangkau kalau batch terakhir sudah dibatalkan (voided) tapi ada
        // batch lama yang terlanjur diposting -- latestActiveBatch() melewatkan
        // yang voided, jadi cek ini tidak redundan.
        if (OpeningBalanceBatch::query()->whereIn('status', ['posted', 'locked'])->exists()) {
            return 'Saldo awal sudah diposting/dikunci. Buka kembali (reopen) dulu di halaman Saldo Awal sebelum mengimpor.';
        }

        return null;
    }

    /**
     * Urutan wajib: aset tetap awal DULU, saldo awal belakangan.
     *
     * Baris harga perolehan dan akumulasi penyusutan di batch saldo awal tidak
     * pernah diketik — `OpeningBalanceBatchService::fixedAssetSystemLines()`
     * menghasilkannya otomatis dari tabel `fixed_assets` yang bersumber
     * `opening_import`, dipecah per akun kelas aset. Mengimpor saldo awal lebih
     * dulu berarti mengunci neraca pembuka pada angka yang belum memuat aset
     * sama sekali: user melihat batch yang seimbang, memposting, lalu aset yang
     * diimpor sesudahnya tidak punya lagi tempat untuk dibukukan (impor aset
     * ditolak `FixedAssetOpeningImportCommitter::preconditionError()` begitu
     * saldo awal diposting) — dan selisihnya baru ketahuan saat neraca dibaca.
     *
     * Menolaknya di sini memindahkan kegagalan itu ke titik paling awal yang
     * masih bisa diperbaiki tanpa reopen.
     *
     * Dua jalan keluar yang sah, sama persis dengan yang dipakai
     * `SetupWizardService::validateOpeningFixedAssets()` supaya wizard dan impor
     * tidak pernah berbeda pendapat:
     *   1. register sudah berisi aset ber-`source_type = 'opening_import'`, atau
     *   2. user sudah menyatakan perusahaan ini tidak punya aset tetap awal
     *      (`opening_fixed_assets_confirmed_none` di metadata setup).
     */
    private function openingFixedAssetsPrecondition(): ?string
    {
        if (! $this->fixedAssetsEnabled()) {
            return null;
        }

        if (! Schema::connection('tenant')->hasTable('fixed_assets')) {
            return 'Modul Aktiva Tetap aktif untuk perusahaan ini, tapi tabel aset tetap belum tersedia. Hubungi admin sebelum mengimpor saldo awal.';
        }

        if (FixedAsset::query()->where('source_type', 'opening_import')->exists()) {
            return null;
        }

        if ($this->confirmedNoOpeningFixedAssets()) {
            return null;
        }

        return 'Modul Aktiva Tetap aktif, tapi aset tetap awal belum diimpor. Impor berkas Aset Tetap Awal dulu — baris harga perolehan dan akumulasi penyusutannya dibuat otomatis dari data itu, jadi urutannya tidak bisa dibalik. Kalau perusahaan ini memang tidak punya aset tetap awal, centang konfirmasinya di wizard Setup langkah Saldo Awal.';
    }

    /**
     * Pernyataan eksplisit "tidak punya aset tetap awal" dari wizard Setup.
     * Dibaca langsung dari `company_setup_states`, bukan lewat SetupWizardService:
     * memanggil service itu dari sini akan membuat coupling Imports → Setup yang
     * belum ada di baseline `ModuleBoundariesTest`, sementara yang dibutuhkan
     * cuma satu flag boolean.
     */
    private function confirmedNoOpeningFixedAssets(): bool
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return false;
        }

        // `first()`, bukan `value('metadata')`: kolomnya JSON dengan cast `array`
        // di model, dan `value()` melewati cast itu -- yang kembali string mentah.
        $state = CompanySetupState::query()->where('company_id', $company->id)->first();
        if (! $state instanceof CompanySetupState) {
            return false;
        }

        return (bool) (((array) $state->metadata)['opening_fixed_assets_confirmed_none'] ?? false);
    }

    private function fixedAssetsEnabled(): bool
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return false;
        }

        return (bool) CompanyModuleSetting::query()->where('company_id', $company->id)->value('fixed_asset_enabled');
    }

    private function accountAlreadyInBatch(int $accountId): bool
    {
        $batch = $this->openingBalanceBatchService->latestActiveBatch();
        if (! $batch instanceof OpeningBalanceBatch) {
            return false;
        }

        return $batch->lines()->where('account_id', $accountId)->exists();
    }

    /**
     * @return list<int>
     */
    private function fixedAssetControlAccountIds(): array
    {
        return AccountMapping::query()
            ->whereIn('mapping_key', self::FIXED_ASSET_CONTROL_KEYS)
            ->where('is_active', true)
            ->whereNotNull('account_id')
            ->pluck('account_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  iterable<object>  $rows
     * @return array<int, array{status: string, document_id: ?int, document_type: ?string, error: ?string}>
     */
    private function failAll(iterable $rows, string $message): array
    {
        $results = [];
        foreach ($rows as $row) {
            $results[$row->id] = ['status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => $message];
        }

        return $results;
    }
}
