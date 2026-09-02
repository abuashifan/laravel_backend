<?php

namespace App\Modules\Imports\Services\Committers;

use App\Modules\FixedAssets\Models\FixedAsset;
use App\Modules\FixedAssets\Models\FixedAssetCategory;
use App\Modules\FixedAssets\Services\FixedAssetService;
use App\Modules\FixedAssets\Support\OpeningAccumulatedDepreciation;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Project;
use App\Modules\OpeningBalance\Models\OpeningBalanceBatch;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\CompanyModuleSetting;
use App\Shared\Models\FiscalYear;
use App\Shared\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Profil impor aset tetap awal — Fase 7.
 *
 * Mengisi register aset warisan milik klien yang baru pindah ke aplikasi ini:
 * aset yang sudah dibeli bertahun-tahun lalu, sudah menyusut sebagian, dan
 * harus muncul di neraca pembuka dengan nilai buku yang benar.
 *
 * ── Yang SENGAJA tidak dilakukan committer ini ──────────────────────────────
 *
 * 1. **Tidak memposting jurnal.** Aturan tertulis di
 *    `docs/implementation_plans/fixed-assets-implementation-plan.md` baris 568:
 *    "Opening fixed asset import must create register records only and must not
 *    create a standalone GL journal." Harga perolehan dan akumulasi penyusutan
 *    dibukukan SEKALI saja, lewat baris sistem batch saldo awal
 *    (`OpeningBalanceBatchService::fixedAssetSystemLines()`). Memanggil
 *    `capitalize()` di sini akan membukukan asetnya dua kali.
 * 2. **Tidak mengkapitalisasi.** Aset berhenti di status `draft`. Ia diaktifkan
 *    — beserta jadwal penyusutan sisa nilai/sisa umurnya — oleh
 *    `FixedAssetService::activateOpeningAssets()` saat batch saldo awalnya
 *    diposting. Impor hanya mengisi register; yang menghidupkannya adalah
 *    peristiwa yang juga membukukannya ke buku besar.
 *
 * Penanda `source_type = 'opening_import'` yang ditulis di bawah adalah yang
 * dibaca `SetupWizardService::validateOpeningFixedAssets()` (step wizard lolos
 * tanpa checkbox "belum punya aset tetap") DAN
 * `OpeningBalanceBatchService::openingFixedAssetTotals()`.
 */
class FixedAssetOpeningImportCommitter implements ImportProfileCommitter, ProvidesImportWarnings
{
    use Concerns\NormalizesImportDates;

    /** Sejajar dengan aturan `in:` di `StoreFixedAssetRequest`. */
    private const ALLOWED_USEFUL_LIFE_YEARS = [4, 8, 10, 16, 20];

    /** Hasil `prospectiveOpeningDate()`, di-cache: warnRow() dipanggil per baris. */
    private ?string $openingDate = null;

    /** @var array<string, FixedAssetCategory|null> */
    private array $categoryCache = [];

    public function __construct(
        private readonly FixedAssetService $fixedAssetService,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, string>  $normalized
     * @return array<string, list<string>>
     */
    public function validateRow(ImportBatch $batch, array $normalized): array
    {
        // Prasyarat tingkat perusahaan. Sengaja dilaporkan per baris, bukan
        // ditahan sampai commit: kalau modulnya mati atau saldo awal sudah
        // diposting, SETIAP baris memang tidak bisa masuk — dan user berhak
        // tahu itu di layar pratinjau, bukan setelah menekan Commit.
        if ($precondition = $this->preconditionError()) {
            return ['name' => [$precondition]];
        }

        $errors = [];

        $category = trim((string) ($normalized['category'] ?? ''));
        $acquisitionDate = trim((string) ($normalized['acquisition_date'] ?? ''));
        $serviceStart = trim((string) ($normalized['service_start_date'] ?? ''));
        $cost = trim((string) ($normalized['acquisition_cost'] ?? ''));
        $accumulated = trim((string) ($normalized['accumulated_depreciation'] ?? ''));
        $salvage = trim((string) ($normalized['salvage_value'] ?? ''));
        $life = trim((string) ($normalized['useful_life_years'] ?? ''));
        $quantity = trim((string) ($normalized['quantity'] ?? ''));
        $department = trim((string) ($normalized['department'] ?? ''));
        $project = trim((string) ($normalized['project'] ?? ''));

        // ── Category ────────────────────────────────────────────────────
        $resolvedCategory = $this->findCategory($category);

        if ($category !== '' && ! $resolvedCategory instanceof FixedAssetCategory) {
            $errors['category'][] = "Kategori '{$category}' tidak ditemukan atau tidak aktif. Pakai kode kategori (mis. VEHICLE, IT_EQUIP) atau namanya persis seperti di halaman Kategori Aset Tetap.";
        }

        // Kategori yang TIDAK menyusut wajib punya Akun Aktiva sendiri.
        //
        // `FixedAssetService::assetAccount()` jatuh ke mapping global
        // `fixed_assets.cost` saat kolom kategori null, dan mapping itu menunjuk
        // akun Peralatan — akun untuk aset yang DISUSUTKAN. Untuk Tanah, Aset
        // Dalam Penyelesaian, dan Goodwill (satu-satunya kategori bawaan yang
        // `depreciation_type`-nya bukan depreciation/amortization) fallback itu
        // bukan sekadar kurang tepat, ia salah kelas: pos neraca yang tidak pernah
        // disusutkan tidak boleh mendarat di akun aset yang disusutkan.
        //
        // Sebelum penjagaan ini tidak ada satu pun galat yang terbit — tidak di
        // pratinjau impor, tidak saat validasi saldo awal, tidak saat posting.
        // Justru fallback yang BERFUNGSI itulah yang membuatnya senyap: kalau
        // `fixed_assets.cost` ikut kosong, `mapping()` akan melempar
        // ACCOUNT_MAPPING_MISSING dan user langsung tahu ada yang salah.
        //
        // Kategori yang menyusut sengaja TIDAK dijaga di sini: fallback ke akun
        // Peralatan memang perilaku yang didokumentasikan (lihat docblock
        // `accumulatedAccount()`), dan tenant yang baru dimigrasi belum memetakan
        // satu pun kolom akun kategori — menolaknya akan memblokir seluruh impor
        // pada perusahaan baru, bukan cuma kasus yang benar-benar salah.
        if ($resolvedCategory instanceof FixedAssetCategory
            && ! $resolvedCategory->asset_account_id
            && ! in_array((string) $resolvedCategory->depreciation_type, ['depreciation', 'amortization'], true)) {
            $errors['category'][] = sprintf(
                'Kategori %s tidak disusutkan tapi belum punya Akun Aktiva sendiri, sehingga harga perolehannya akan jatuh ke akun Peralatan — akun untuk aset yang disusutkan. Buat akunnya di Daftar Akun, lalu petakan di Kategori Aset Tetap → kolom Akun Aktiva.',
                $resolvedCategory->name,
            );
        }

        // ── Acquisition Date ────────────────────────────────────────────
        $parsedAcquisition = null;
        if ($acquisitionDate !== '') {
            $parsedAcquisition = $this->parseImportDate($acquisitionDate);
            if ($parsedAcquisition === null) {
                $errors['acquisition_date'][] = 'Acquisition Date harus dalam format DD/MM/YYYY dan tanggal valid.';
            }
        }

        // ── Service Start Date (opsional) ───────────────────────────────
        if ($serviceStart !== '') {
            $parsedServiceStart = $this->parseImportDate($serviceStart);
            if ($parsedServiceStart === null) {
                $errors['service_start_date'][] = 'Service Start Date harus dalam format DD/MM/YYYY dan tanggal valid.';
            } elseif ($parsedAcquisition instanceof CarbonImmutable && $parsedServiceStart->lt($parsedAcquisition)) {
                $errors['service_start_date'][] = 'Service Start Date tidak boleh lebih awal dari Acquisition Date.';
            }
        }

        // ── Angka ───────────────────────────────────────────────────────
        $costValue = $this->numeric($cost, 'acquisition_cost', 'Acquisition Cost', $errors);
        $accumulatedValue = $this->numeric($accumulated, 'accumulated_depreciation', 'Accumulated Depreciation', $errors);
        $salvageValue = $this->numeric($salvage, 'salvage_value', 'Salvage Value', $errors);

        if ($cost !== '' && $costValue !== null && $costValue <= 0) {
            $errors['acquisition_cost'][] = 'Acquisition Cost harus lebih besar dari 0.';
        }

        if ($costValue !== null && $accumulatedValue !== null) {
            $basis = round($costValue - ($salvageValue ?? 0), 2);
            if ($accumulatedValue > $basis) {
                $errors['accumulated_depreciation'][] = sprintf(
                    'Accumulated Depreciation (%s) melebihi Acquisition Cost dikurangi Salvage Value (%s).',
                    number_format($accumulatedValue, 2, ',', '.'),
                    number_format($basis, 2, ',', '.'),
                );
            }
        }

        if ($quantity !== '') {
            if (! is_numeric($quantity)) {
                $errors['quantity'][] = 'Quantity harus berupa angka.';
            } elseif ((float) $quantity <= 0) {
                $errors['quantity'][] = 'Quantity harus lebih besar dari 0.';
            }
        }

        // ── Useful Life Years ───────────────────────────────────────────
        if ($life !== '') {
            if (! ctype_digit($life) || ! in_array((int) $life, self::ALLOWED_USEFUL_LIFE_YEARS, true)) {
                $errors['useful_life_years'][] = 'Useful Life Years hanya boleh '.implode(', ', self::ALLOWED_USEFUL_LIFE_YEARS).' (kelompok masa manfaat pajak).';
            }
        }

        // ── Dimensi opsional ────────────────────────────────────────────
        if ($department !== '' && ! Department::query()->where('code', $department)->where('is_active', true)->exists()) {
            $errors['department'][] = "Departemen '{$department}' tidak ditemukan atau tidak aktif.";
        }

        if ($project !== '' && ! Project::query()->where('code', $project)->where('is_active', true)->where('status', 'active')->exists()) {
            $errors['project'][] = "Proyek '{$project}' tidak ditemukan, tidak aktif, atau sudah selesai.";
        }

        return $errors;
    }

    /**
     * Peringatan pratinjau — semuanya sah untuk data warisan, jadi tidak ada
     * yang menggagalkan baris. Yang dikejar di sini adalah salah ketik yang
     * lolos semua aturan formal: umur 20 tahun untuk laptop, akumulasi satu
     * digit meleset, aset yang sebenarnya pembelian baru dan bukan saldo awal.
     *
     * @param  array<string, string>  $normalized
     * @return array<string, list<string>>
     */
    public function warnRow(ImportBatch $batch, array $normalized): array
    {
        $category = $this->findCategory(trim((string) ($normalized['category'] ?? '')));

        if (! $category instanceof FixedAssetCategory) {
            return [];
        }

        $warnings = [];
        $depreciates = in_array((string) $category->depreciation_type, ['depreciation', 'amortization'], true);
        $life = trim((string) ($normalized['useful_life_years'] ?? ''));
        $accumulated = trim((string) ($normalized['accumulated_depreciation'] ?? ''));
        $openingDate = $this->prospectiveOpeningDate();

        // ── Umur manfaat ────────────────────────────────────────────────
        if (! $depreciates) {
            if ($life !== '') {
                $warnings['useful_life_years'][] = sprintf(
                    'Kategori %s tidak disusutkan, jadi Useful Life Years diabaikan.',
                    $category->name,
                );
            }

            if ($accumulated !== '' && (float) $accumulated > 0) {
                $warnings['accumulated_depreciation'][] = sprintf(
                    'Kategori %s tidak disusutkan, tapi baris ini membawa akumulasi penyusutan — angkanya tetap dibukukan ke akun akumulasi penyusutan saat saldo awal diposting.',
                    $category->name,
                );
            }
        } elseif ($life !== '' && $category->default_useful_life_years && (int) $life !== (int) $category->default_useful_life_years) {
            // Bukan galat: dokumen menetapkan umur kategori sebagai SARAN yang
            // boleh diubah user ("Category may suggest a default, but it must
            // remain user-editable before capitalization"). Yang dikejar
            // peringatan ini cuma salah ketik.
            $warnings['useful_life_years'][] = sprintf(
                'Umur manfaat %d tahun berbeda dari default kategori %s (%d tahun). Angka Anda tetap dipakai — pastikan memang begitu di pembukuan lama.',
                (int) $life,
                $category->name,
                (int) $category->default_useful_life_years,
            );
        }

        // ── Tanggal perolehan setelah tanggal saldo awal ────────────────
        $acquisition = $this->parseImportDate(trim((string) ($normalized['acquisition_date'] ?? '')));
        if ($acquisition instanceof CarbonImmutable && $acquisition->gt(CarbonImmutable::parse($openingDate))) {
            $warnings['acquisition_date'][] = sprintf(
                'Tanggal perolehan %s ada SETELAH tanggal saldo awal %s. Aset yang dibeli setelah saldo awal seharusnya masuk lewat tagihan pembelian, bukan impor aset tetap awal.',
                $acquisition->format('d/m/Y'),
                CarbonImmutable::parse($openingDate)->format('d/m/Y'),
            );
        }

        // ── Akumulasi penyusutan ────────────────────────────────────────
        if (! $depreciates) {
            return $warnings;
        }

        $estimate = OpeningAccumulatedDepreciation::estimate(
            (float) trim((string) ($normalized['acquisition_cost'] ?? '0')),
            (float) trim((string) ($normalized['salvage_value'] ?? '0')),
            $this->effectiveLifeYears($life, $category),
            $this->normalizeDate(trim((string) ($normalized['service_start_date'] ?? ''))) ?: $this->normalizeDate(trim((string) ($normalized['acquisition_date'] ?? ''))),
            $openingDate,
        );

        if ($estimate === null) {
            return $warnings;
        }

        if ($accumulated === '') {
            $warnings['accumulated_depreciation'][] = sprintf(
                'Accumulated Depreciation dikosongkan, jadi sistem yang menghitungnya: %s per %s (garis lurus). Isi kolomnya kalau pembukuan lama memakai angka lain.',
                $this->rupiah($estimate),
                CarbonImmutable::parse($openingDate)->format('d/m/Y'),
            );

            return $warnings;
        }

        if (is_numeric($accumulated) && OpeningAccumulatedDepreciation::deviates((float) $accumulated, $estimate)) {
            $warnings['accumulated_depreciation'][] = sprintf(
                'Accumulated Depreciation %s menyimpang dari perkiraan garis lurus %s per %s. Angka Anda tetap dipakai — abaikan kalau pembukuan lama memang bukan garis lurus.',
                $this->rupiah((float) $accumulated),
                $this->rupiah($estimate),
                CarbonImmutable::parse($openingDate)->format('d/m/Y'),
            );
        }

        return $warnings;
    }

    /**
     * @return array<int, array{status: string, document_id: ?int, document_type: ?string, error: ?string}>
     */
    public function commit(ImportBatch $batch): array
    {
        $results = [];
        $rows = $batch->rows()->where('status', 'valid')->orderBy('row_number')->get();

        // Diperiksa ulang di sini, bukan hanya di validateRow: keadaannya bisa
        // berubah antara pratinjau dan commit (mis. saldo awal diposting di tab
        // lain), dan yang satu ini tidak boleh lolos.
        if ($precondition = $this->preconditionError()) {
            foreach ($rows as $row) {
                $results[$row->id] = ['status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => $precondition];
            }

            return $results;
        }

        foreach ($rows as $row) {
            $n = (array) ($row->normalized ?? []);
            $category = $this->findCategory(trim((string) ($n['category'] ?? '')));

            if (! $category instanceof FixedAssetCategory) {
                $results[$row->id] = ['status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => 'Kategori aset tetap tidak ditemukan saat commit.'];

                continue;
            }

            try {
                $asset = $this->fixedAssetService->create($this->assetData($n, $category, $batch, (int) $row->row_number));

                $results[$row->id] = [
                    'status' => 'committed',
                    'document_id' => $asset->id,
                    'document_type' => FixedAsset::class,
                    'error' => null,
                ];
            } catch (ApiException $e) {
                $results[$row->id] = ['status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => $e->getMessage()];
            } catch (Throwable $e) {
                $results[$row->id] = ['status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => 'Gagal membuat aset tetap: '.$e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $n
     * @return array<string, mixed>
     */
    private function assetData(array $n, FixedAssetCategory $category, ImportBatch $batch, int $rowNumber): array
    {
        $acquisitionDate = $this->normalizeDate(trim((string) ($n['acquisition_date'] ?? '')));
        // Tanpa tanggal mulai pakai tidak ada jangkar untuk menghitung sisa umur
        // saat aset diaktifkan, dan `generateOpeningSchedules()` melewatkannya
        // diam-diam. Default ke tanggal perolehan -- itu yang dimaksud hampir
        // semua klien saat mengosongkan kolomnya.
        $serviceStart = $this->normalizeDate(trim((string) ($n['service_start_date'] ?? ''))) ?: $acquisitionDate;
        $life = trim((string) ($n['useful_life_years'] ?? ''));
        $accumulated = trim((string) ($n['accumulated_depreciation'] ?? ''));
        $quantity = trim((string) ($n['quantity'] ?? ''));
        $department = trim((string) ($n['department'] ?? ''));
        $project = trim((string) ($n['project'] ?? ''));

        return [
            'name' => trim((string) ($n['name'] ?? '')),
            'description' => trim((string) ($n['description'] ?? '')) ?: null,
            'fixed_asset_category_id' => $category->id,
            'acquisition_date' => $acquisitionDate,
            'service_start_date' => $serviceStart !== '' ? $serviceStart : null,
            'useful_life_years' => $life !== '' ? (int) $life : null,
            'quantity' => $quantity !== '' ? (float) $quantity : 1,
            'acquisition_cost' => (float) ($n['acquisition_cost'] ?? 0),
            // null, BUKAN 0, saat kolomnya kosong: nol berarti "aset ini belum
            // pernah disusutkan", sementara kolom kosong berarti "hitungkan".
            // `FixedAssetService` yang membedakan keduanya.
            'accumulated_depreciation' => $accumulated !== '' ? (float) $accumulated : null,
            'salvage_value' => (float) ($n['salvage_value'] ?? 0),
            'department_id' => $department !== '' ? Department::query()->where('code', $department)->value('id') : null,
            'project_id' => $project !== '' ? Project::query()->where('code', $project)->value('id') : null,
            'source_type' => 'opening_import',
            'metadata' => [
                'import_batch_uuid' => $batch->uuid,
                'import_row_number' => $rowNumber,
            ],
        ];
    }

    /**
     * Cari kategori: kode persis → kode tanpa peduli besar-kecil → nama tanpa
     * peduli besar-kecil. Hanya kategori aktif. Pola yang sama dengan
     * `ResolvesModelByCodeOrName` untuk kontak/produk, tapi trait itu tidak
     * dipakai karena hanya mengenal dua model master data itu.
     */
    private function findCategory(string $value): ?FixedAssetCategory
    {
        if ($value === '') {
            return null;
        }

        // Di-cache karena tiap baris menanyakan kategorinya sampai tiga kali:
        // sekali di validateRow(), sekali di warnRow(), sekali di commit() --
        // dan berkas seribu baris umumnya hanya memakai segelintir kategori.
        if (array_key_exists($value, $this->categoryCache)) {
            return $this->categoryCache[$value];
        }

        return $this->categoryCache[$value] = $this->lookupCategory($value);
    }

    private function lookupCategory(string $value): ?FixedAssetCategory
    {
        $category = FixedAssetCategory::query()->where('code', $value)->where('is_active', true)->first();
        if ($category instanceof FixedAssetCategory) {
            return $category;
        }

        $category = FixedAssetCategory::query()
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($value)])
            ->where('is_active', true)
            ->first();
        if ($category instanceof FixedAssetCategory) {
            return $category;
        }

        return FixedAssetCategory::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])
            ->where('is_active', true)
            ->first();
    }

    /**
     * Umur manfaat yang benar-benar akan dipakai aset ini: yang diketik di
     * berkas, atau default kategori kalau kolomnya kosong. Harus sejalan
     * dengan `FixedAssetService::assetPayload()` — kalau tidak, perkiraan
     * akumulasi yang diperingatkan di sini berbeda dari yang dihitung sistem.
     */
    private function effectiveLifeYears(string $life, FixedAssetCategory $category): ?int
    {
        if ($life !== '' && ctype_digit($life)) {
            return (int) $life;
        }

        return $category->default_useful_life_years ? (int) $category->default_useful_life_years : null;
    }

    /**
     * Tanggal saldo awal yang KEMUNGKINAN dipakai, untuk keperluan peringatan
     * saja.
     *
     * Saat berkas diimpor, batch saldo awalnya sering belum ada — dan tanggal
     * yang pasti baru diketahui saat batch itu diposting. Urutan tebakannya
     * sama dengan yang dipakai `OpeningBalanceImportCommitter` ketika ia harus
     * membuat batch sendiri (keputusan 7B-2): batch yang masih bisa diisi,
     * lalu awal tahun fiskal aktif, lalu hari ini. Peringatan yang meleset
     * karena tebakan ini tidak berbahaya — angka yang dipakai sistem tetap
     * dihitung ulang saat posting.
     */
    private function prospectiveOpeningDate(): string
    {
        if ($this->openingDate !== null) {
            return $this->openingDate;
        }

        if (Schema::connection('tenant')->hasTable('opening_balance_batches')) {
            $batch = OpeningBalanceBatch::query()
                ->whereIn('status', ['draft', 'reopened'])
                ->orderByDesc('opening_date')
                ->first();

            if ($batch instanceof OpeningBalanceBatch && $batch->opening_date) {
                return $this->openingDate = $batch->opening_date->toDateString();
            }
        }

        $company = $this->tenantContext->company();
        if ($company) {
            $start = FiscalYear::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->orderByDesc('start_date')
                ->value('start_date');

            if ($start) {
                return $this->openingDate = $start instanceof \DateTimeInterface
                    ? $start->format('Y-m-d')
                    : (string) $start;
            }
        }

        return $this->openingDate = CarbonImmutable::now()->toDateString();
    }

    private function rupiah(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }

    /**
     * Alasan berkas ini tidak bisa diimpor sama sekali, atau null kalau aman.
     */
    private function preconditionError(): ?string
    {
        if (! $this->fixedAssetsEnabled()) {
            return 'Modul Aktiva Tetap belum aktif untuk perusahaan ini. Aktifkan dulu di Pengaturan → Modul sebelum mengimpor aset tetap awal.';
        }

        if (Schema::connection('tenant')->hasTable('opening_balance_batches')
            && OpeningBalanceBatch::query()->whereIn('status', ['posted', 'locked'])->exists()) {
            // Total aset tetap ikut membentuk baris sistem batch saldo awal.
            // Menambah aset setelah batch diposting akan membuat register aset
            // dan jurnal pembuka tidak lagi cocok, tanpa jejak apa pun.
            return 'Saldo awal sudah diposting/dikunci. Aset tetap awal harus diimpor SEBELUM saldo awal diposting — kalau memang perlu, buka kembali (reopen) saldo awalnya dulu.';
        }

        return null;
    }

    private function fixedAssetsEnabled(): bool
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return false;
        }

        return (bool) CompanyModuleSetting::query()->where('company_id', $company->id)->value('fixed_asset_enabled');
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function numeric(string $raw, string $field, string $label, array &$errors): ?float
    {
        if ($raw === '') {
            return null;
        }

        if (! is_numeric($raw)) {
            $errors[$field][] = "{$label} harus berupa angka.";

            return null;
        }

        if ((float) $raw < 0) {
            $errors[$field][] = "{$label} tidak boleh negatif.";

            return null;
        }

        return (float) $raw;
    }
}
