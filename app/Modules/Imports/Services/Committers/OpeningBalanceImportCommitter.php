<?php

namespace App\Modules\Imports\Services\Committers;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\OpeningBalance\Services\OpeningBalanceService;
use App\Shared\Exceptions\ApiException;
use Throwable;

/**
 * Profil impor saldo awal — Fase 8.
 *
 * **Ini impor jurnal umum**, tidak lebih. Berkas neraca saldo klien diimpor apa
 * adanya, satu sisi per baris; selisih seluruh berkas jatuh ke akun perantara
 * sebagai satu baris lawan yang dihitung `OpeningBalanceService`. Hasilnya satu
 * jurnal terposting bersumber `opening_balance`.
 *
 * ── Yang SENGAJA tidak ada di sini ──────────────────────────────────────────
 *
 * Sampai Fase 7, committer ini 418 baris: prasyarat urutan impor, daftar akun
 * kontrol aset tetap yang ditolak per baris, penggabungan ke baris batch yang
 * sedang berjalan, dan penolakan kalau batch itu sudah diposting. Semuanya
 * gugur bersama modul batch (lihat `OpeningBalanceService`).
 *
 * Yang paling penting hilang: **akun aset tetap tidak lagi ditolak.** Harga
 * perolehan dan akumulasi penyusutan diisi lewat berkas ini seperti akun lain;
 * impor aset tetap cuma mendaftar kartunya. Karena itu urutan kedua impor
 * bebas, dan berkas neraca saldo klien tidak perlu dipreteli lebih dulu.
 *
 * Berkas boleh diimpor berkali-kali — kas hari ini, piutang besok. Tiap berkas
 * jadi jurnal sendiri yang bisa dibatalkan sendiri.
 */
class OpeningBalanceImportCommitter implements ImportProfileCommitter, RevertsImport
{
    use Concerns\DetectsDuplicateCodesInBatch;

    public function __construct(private readonly OpeningBalanceService $openingBalanceService) {}

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
        if ($accountCode !== '') {
            $account = ChartOfAccount::query()->where('account_code', $accountCode)->first();

            if (! $account instanceof ChartOfAccount) {
                $errors['account_code'][] = "Akun dengan kode '{$accountCode}' tidak ditemukan.";
            } elseif (! $account->is_active) {
                $errors['account_code'][] = "Akun '{$accountCode}' tidak aktif.";
            } elseif ($account->children()->exists()) {
                $errors['account_code'][] = "Akun '{$accountCode}' adalah akun induk. Saldo awal hanya bisa diisi ke akun daun (leaf account).";
            } elseif (in_array((string) $account->account_type, ['revenue', 'expense'], true)) {
                // Satu-satunya jenis akun yang masih ditolak. Neraca pembuka
                // memuat posisi, bukan kinerja periode lalu -- pendapatan dan
                // beban tahun sebelumnya sudah melebur jadi laba ditahan.
                $errors['account_code'][] = "Akun '{$accountCode}' adalah akun nominal (pendapatan/beban). Saldo awal hanya untuk akun neraca — laba periode lalu masuk lewat akun ekuitas.";
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

        $lines = [];
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
                'metadata' => [
                    'import_batch_uuid' => $batch->uuid,
                    'import_row_number' => (int) $row->row_number,
                ],
            ];
            $committed[] = $row;
        }

        if ($lines === []) {
            return $results;
        }

        // Satu panggilan untuk seluruh berkas: jurnalnya satu, jadi kegagalan di
        // tengah tidak menyisakan setengah berkas yang terbukukan.
        try {
            $journal = $this->openingBalanceService->postOpeningJournal($lines, [
                'description' => 'Saldo awal — impor '.$batch->original_filename,
                'metadata' => ['import_batch_uuid' => $batch->uuid],
            ]);
        } catch (ApiException $e) {
            return $this->failAll($rows, $e->getMessage());
        } catch (Throwable $e) {
            return $this->failAll($rows, 'Gagal memposting jurnal saldo awal: '.$e->getMessage());
        }

        foreach ($committed as $row) {
            $results[$row->id] = [
                'status' => 'committed',
                'document_id' => (int) $journal->id,
                'document_type' => JournalEntry::class,
                'error' => null,
            ];
        }

        return $results;
    }

    /**
     * Kebalikan commit: jurnal yang dihasilkan berkas ini di-void.
     *
     * Mem-void, bukan menghapus — jurnal pembuka tetap immutable seperti jurnal
     * lain, dan jejaknya termasuk yang paling dibutuhkan justru saat ada yang
     * salah.
     */
    public function revert(ImportBatch $batch, string $reason): int
    {
        $journalIds = $batch->rows()
            ->where('status', 'committed')
            ->where('document_type', JournalEntry::class)
            ->whereNotNull('document_id')
            ->pluck('document_id')
            ->unique()
            ->values();

        $reverted = 0;
        foreach ($journalIds as $journalId) {
            $this->openingBalanceService->voidOpeningJournal((int) $journalId, $reason);
            $reverted++;
        }

        return $reverted;
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
