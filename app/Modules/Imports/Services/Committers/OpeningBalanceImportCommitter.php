<?php

namespace App\Modules\Imports\Services\Committers;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\OpeningBalance\Models\OpeningBalanceBatch;
use App\Modules\OpeningBalance\Services\OpeningBalanceBatchService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\FiscalYear;
use App\Shared\Tenant\TenantContext;
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
 */
class OpeningBalanceImportCommitter implements ImportProfileCommitter
{
    use Concerns\DetectsDuplicateCodesInBatch;

    /**
     * Kunci mapping yang menghasilkan baris sistem aset tetap. Generik dan
     * per-kelas keduanya didaftar: yang generik yang dipakai
     * `fixedAssetSystemLines()` hari ini, yang per-kelas dipakai kategori aset
     * (`config/fixed_asset_categories.php`) dan akan dipakai kalau baris sistem
     * dipecah per kelas nanti. Menolak keduanya sekarang membuat berkas yang
     * sah hari ini tetap sah setelah pemecahan itu.
     */
    private const FIXED_ASSET_CONTROL_KEYS = [
        'fixed_assets.cost',
        'fixed_assets.accumulated_depreciation',
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
