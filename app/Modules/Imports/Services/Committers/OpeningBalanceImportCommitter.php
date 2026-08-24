<?php

namespace App\Modules\Imports\Services\Committers;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\OpeningBalance\Models\OpeningBalanceBatch;
use App\Modules\OpeningBalance\Services\OpeningBalanceBatchService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\CompanySetupState;
use App\Shared\Tenant\TenantContext;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Profil impor saldo awal.
 *
 * Berbeda dari profil lain yang membuat SATU dokumen per kelompok Ref: seluruh
 * berkas di sini masuk ke SATU batch saldo awal, karena backend hanya
 * mengizinkan satu batch aktif per perusahaan
 * (OPENING_BALANCE_ACTIVE_BATCH_EXISTS). Jadi tidak ada kolom Ref sama sekali —
 * satu baris berkas = satu baris saldo awal.
 *
 * Impor bersifat MENAMBAH, bukan menimpa: baris yang sudah ada di batch draft
 * dikirim ulang bersama baris baru, sebab `replaceLines()` mengganti seluruh
 * isi batch. Tanpa itu, mengimpor berkas kedua akan menghapus hasil impor
 * pertama.
 *
 * Batch dibiarkan berstatus `draft` setelah impor — penyeimbangan, validasi,
 * dan posting tetap keputusan sadar user di halaman Saldo Awal, bukan efek
 * samping sebuah unggahan berkas.
 */
class OpeningBalanceImportCommitter implements ImportProfileCommitter
{
    /** Saldo awal hanya untuk akun riil; akun nominal ditolak. */
    private const REAL_ACCOUNT_TYPES = ['asset', 'liability', 'equity'];

    public function __construct(
        private readonly OpeningBalanceBatchService $batches,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, string>  $normalized
     * @return array<string, list<string>>
     */
    public function validateRow(ImportBatch $batch, array $normalized): array
    {
        $errors = [];

        $accountCode = trim((string) ($normalized['account_code'] ?? ''));
        $debit = trim((string) ($normalized['debit'] ?? ''));
        $credit = trim((string) ($normalized['credit'] ?? ''));

        // ── Account Code ────────────────────────────────────────────────
        if ($accountCode === '') {
            $errors['account_code'][] = 'Account Code wajib diisi.';
        } else {
            $account = ChartOfAccount::query()
                ->where('account_code', $accountCode)
                ->where('is_active', true)
                ->first();

            if (! $account instanceof ChartOfAccount) {
                $errors['account_code'][] = "Akun dengan kode '{$accountCode}' tidak ditemukan atau tidak aktif.";
            } elseif ($account->children()->exists()) {
                $errors['account_code'][] = "Akun '{$accountCode}' adalah akun induk. Saldo awal hanya bisa diisi pada akun daun (leaf account).";
            } elseif (! in_array((string) $account->account_type, self::REAL_ACCOUNT_TYPES, true)) {
                $errors['account_code'][] = "Akun '{$accountCode}' bertipe {$account->account_type}. Saldo awal hanya untuk akun riil (asset, liability, equity).";
            } elseif ($this->isAccountCodeUsedEarlierInBatch($batch, $accountCode)) {
                $errors['account_code'][] = "Akun '{$accountCode}' muncul lebih dari sekali di berkas ini. Gabungkan menjadi satu baris.";
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

        if (! isset($errors['debit']) && ! isset($errors['credit'])) {
            if (! $hasDebit && ! $hasCredit) {
                $errors['debit'][] = 'Debit atau Credit harus diisi dengan nilai > 0.';
                $errors['credit'][] = 'Debit atau Credit harus diisi dengan nilai > 0.';
            } elseif ($hasDebit && $hasCredit) {
                $errors['debit'][] = 'Tidak boleh mengisi Debit dan Credit sekaligus dalam satu baris.';
                $errors['credit'][] = 'Tidak boleh mengisi Debit dan Credit sekaligus dalam satu baris.';
            }
        }

        // ── Batch saldo awal harus masih bisa diubah ────────────────────
        $target = $this->batches->latestActiveBatch();
        if ($target instanceof OpeningBalanceBatch && ! $target->editable()) {
            $errors['account_code'][] = "Batch saldo awal {$target->batch_number} berstatus {$target->status} dan tidak bisa diubah lagi. Buka kembali batch tersebut sebelum mengimpor.";
        } elseif ($target instanceof OpeningBalanceBatch && $accountCode !== '' && ! isset($errors['account_code'])) {
            // Impor MENAMBAH baris, tidak menimpa yang sudah ada -- kebijakan yang
            // sama dengan impor COA. Tanpa penolakan eksplisit di sini, mengunggah
            // ulang berkas yang sudah dikoreksi akan dilaporkan "berhasil" padahal
            // angka lamanya yang tetap dipakai; user tidak punya cara tahu.
            $alreadyInBatch = $target->lines()
                ->whereHas('account', fn ($query) => $query->where('account_code', $accountCode))
                ->exists();

            if ($alreadyInBatch) {
                $errors['account_code'][] = "Akun '{$accountCode}' sudah punya baris di batch saldo awal {$target->batch_number}. Ubah nilainya langsung di halaman Saldo Awal, atau hapus barisnya dulu bila ingin mengimpor ulang.";
            }
        }

        return $errors;
    }

    /**
     * Kode akun yang sama dua kali dalam satu berkas hampir selalu salah ketik:
     * `replaceLines()` akan menyimpan keduanya sebagai baris terpisah untuk akun
     * yang sama, dan totalnya jadi sulit dicocokkan dengan neraca sumber.
     */
    private function isAccountCodeUsedEarlierInBatch(ImportBatch $batch, string $accountCode): bool
    {
        return ImportRow::query()
            ->where('import_batch_id', $batch->id)
            ->whereNot('status', 'invalid')
            ->get()
            ->contains(fn (ImportRow $row): bool => strcasecmp(
                trim((string) (((array) $row->normalized)['account_code'] ?? '')),
                $accountCode
            ) === 0);
    }

    /**
     * @return array<int, array{status: string, document_id: ?int, document_type: ?string, error: ?string}>
     */
    public function commit(ImportBatch $batch): array
    {
        $rows = $batch->rows()->where('status', 'valid')->orderBy('row_number')->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $skippedRowIds = [];

        try {
            $target = $this->resolveTargetBatch();

            // Baris lama ikut dikirim: replaceLines() mengganti SELURUH isi batch.
            $target->loadMissing('lines');
            $lines = $target->lines
                ->map(fn ($line): array => [
                    'account_id' => (int) $line->account_id,
                    'debit' => round((float) $line->debit, 2),
                    'credit' => round((float) $line->credit, 2),
                    'description' => $line->description,
                ])
                ->all();

            $existingAccountIds = array_column($lines, 'account_id');
            $appendedAccountIds = [];

            foreach ($rows as $row) {
                $normalized = (array) ($row->normalized ?? []);
                $accountCode = trim((string) ($normalized['account_code'] ?? ''));
                $account = ChartOfAccount::query()->where('account_code', $accountCode)->first();
                if (! $account instanceof ChartOfAccount) {
                    continue;
                }

                // Sudah ditolak di validateRow(); jaring pengaman kalau batch
                // berubah antara preview dan commit.
                if (in_array((int) $account->id, $existingAccountIds, true)) {
                    $skippedRowIds[$row->id] = "Akun '{$accountCode}' sudah punya baris di batch saldo awal ini.";

                    continue;
                }

                $lines[] = [
                    'account_id' => (int) $account->id,
                    'debit' => round((float) ($normalized['debit'] ?? 0), 2),
                    'credit' => round((float) ($normalized['credit'] ?? 0), 2),
                    'description' => trim((string) ($normalized['description'] ?? '')) ?: 'Opening balance (impor)',
                ];
                $existingAccountIds[] = (int) $account->id;
                $appendedAccountIds[] = (int) $account->id;
            }

            $target = $this->batches->replaceLines($target, $lines);
        } catch (ApiException $exception) {
            return $this->allRowsFailed($rows, $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->allRowsFailed($rows, 'Gagal menyimpan saldo awal: '.$exception->getMessage());
        }

        $results = [];
        foreach ($rows as $row) {
            if (isset($skippedRowIds[$row->id])) {
                $results[$row->id] = ['status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => $skippedRowIds[$row->id]];

                continue;
            }

            $normalized = (array) ($row->normalized ?? []);
            $accountCode = trim((string) ($normalized['account_code'] ?? ''));
            $accountId = (int) ChartOfAccount::query()->where('account_code', $accountCode)->value('id');

            $results[$row->id] = in_array($accountId, $appendedAccountIds, true)
                ? ['status' => 'committed', 'document_id' => (int) $target->id, 'document_type' => OpeningBalanceBatch::class, 'error' => null]
                : ['status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => 'Akun tidak dapat dipetakan ke baris saldo awal.'];
        }

        return $results;
    }

    /**
     * Batch aktif dipakai ulang kalau ada; kalau belum ada, dibuat baru dengan
     * tanggal pembukuan dari setup wizard (`company_setup_states.opening_date`)
     * supaya saldo awal hasil impor jatuh pada tanggal yang sama dengan yang
     * sudah disepakati di wizard, bukan tanggal berkas diunggah.
     */
    private function resolveTargetBatch(): OpeningBalanceBatch
    {
        $existing = $this->batches->latestActiveBatch();
        if ($existing instanceof OpeningBalanceBatch) {
            return $existing;
        }

        return $this->batches->create(['opening_date' => $this->defaultOpeningDate()]);
    }

    private function defaultOpeningDate(): string
    {
        $company = $this->tenantContext->company();
        $openingDate = $company
            ? CompanySetupState::query()->where('company_id', $company->id)->value('opening_date')
            : null;

        if ($openingDate === null) {
            return now()->toDateString();
        }

        return $openingDate instanceof \DateTimeInterface
            ? $openingDate->format('Y-m-d')
            : (string) $openingDate;
    }

    /**
     * @param  Collection<int, ImportRow>  $rows
     * @return array<int, array{status: string, document_id: ?int, document_type: ?string, error: ?string}>
     */
    private function allRowsFailed($rows, string $message): array
    {
        $results = [];
        foreach ($rows as $row) {
            $results[$row->id] = ['status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => $message];
        }

        return $results;
    }
}
