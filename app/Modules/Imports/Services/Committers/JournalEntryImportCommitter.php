<?php

namespace App\Modules\Imports\Services\Committers;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\Journal\Services\JournalEntryService;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Project;
use App\Shared\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Profil impor jurnal umum — Fase 4.
 *
 * Paling sederhana secara akuntansi (tidak ada stok, piutang/utang),
 * tapi punya syarat keras: debit = kredit per kelompok (Ref).
 *
 * Syarat lain: akun harus ada & aktif, bukan akun induk (leaf account only).
 * Dimensi departemen/proyek bersifat opsional.
 */
class JournalEntryImportCommitter implements ImportProfileCommitter
{
    use Concerns\NormalizesImportDates;

    public function __construct(private readonly JournalEntryService $journalService) {}

    /**
     * @param  array<string, string>  $normalized
     * @return array<string, list<string>>
     */
    public function validateRow(ImportBatch $batch, array $normalized): array
    {
        $errors = [];

        $ref = trim((string) ($normalized['ref'] ?? ''));
        $journalDate = trim((string) ($normalized['journal_date'] ?? ''));
        $accountCode = trim((string) ($normalized['account_code'] ?? ''));
        $debit = trim((string) ($normalized['debit'] ?? ''));
        $credit = trim((string) ($normalized['credit'] ?? ''));
        $department = trim((string) ($normalized['department'] ?? ''));
        $project = trim((string) ($normalized['project'] ?? ''));

        // ── Ref ─────────────────────────────────────────────────────────
        if ($ref === '') {
            $errors['ref'][] = 'Ref wajib diisi.';
        }

        // ── Journal Date ────────────────────────────────────────────────
        $parsedDate = null;
        if ($journalDate === '') {
            $errors['journal_date'][] = 'Journal Date wajib diisi.';
        } else {
            $parsedDate = $this->parseImportDate($journalDate);
            if ($parsedDate === null) {
                $errors['journal_date'][] = 'Journal Date harus dalam format DD/MM/YYYY dan tanggal valid.';
            }
        }

        // ── Account Code ────────────────────────────────────────────────
        $account = null;
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
                $errors['account_code'][] = "Akun '{$accountCode}' adalah akun induk. Posting hanya bisa ke akun daun (leaf account).";
            }
        }

        // ── Debit / Credit ──────────────────────────────────────────────
        $hasDebit = $debit !== '' && ((float) $debit) > 0;
        $hasCredit = $credit !== '' && ((float) $credit) > 0;

        if (! $hasDebit && ! $hasCredit) {
            $errors['debit'][] = 'Debit atau Credit harus diisi dengan nilai > 0.';
            $errors['credit'][] = 'Debit atau Credit harus diisi dengan nilai > 0.';
        } elseif ($hasDebit && $hasCredit) {
            $errors['debit'][] = 'Tidak boleh mengisi Debit dan Credit sekaligus dalam satu baris.';
            $errors['credit'][] = 'Tidak boleh mengisi Debit dan Credit sekaligus dalam satu baris.';
        }

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

        // ── Department (opsional) ───────────────────────────────────────
        if ($department !== '') {
            $dept = Department::query()->where('code', $department)->where('is_active', true)->first();
            if (! $dept instanceof Department) {
                $errors['department'][] = "Departemen '{$department}' tidak ditemukan atau tidak aktif.";
            }
        }

        // ── Project (opsional) ──────────────────────────────────────────
        if ($project !== '') {
            $proj = Project::query()->where('code', $project)->where('is_active', true)->where('status', 'active')->first();
            if (! $proj instanceof Project) {
                $errors['project'][] = "Proyek '{$project}' tidak ditemukan, tidak aktif, atau sudah selesai.";
            }
        }

        // ── Group consistency: tanggal harus sama dalam satu ref ───────
        if ($ref !== '' && $parsedDate instanceof CarbonImmutable) {
            $earlierRow = ImportRow::query()
                ->where('import_batch_id', $batch->id)
                ->where('external_ref', $ref)
                ->whereNot('status', 'invalid')
                ->orderBy('row_number')
                ->first();

            if ($earlierRow instanceof ImportRow) {
                $earlierNormalized = (array) ($earlierRow->normalized ?? []);
                $earlierDate = trim((string) ($earlierNormalized['journal_date'] ?? ''));

                if ($earlierDate !== '' && $earlierDate !== $journalDate) {
                    $errors['ref'][] = "Ref '{$ref}' sudah dipakai di baris {$earlierRow->row_number} dengan tanggal berbeda ({$earlierDate}). Semua baris dengan Ref sama harus punya tanggal yang sama.";
                }
            }
        }

        return $errors;
    }

    /**
     * @return array<int, array{status: string, document_id: ?int, document_type: ?string, error: ?string}>
     */
    public function commit(ImportBatch $batch): array
    {
        $results = [];

        $validRows = $batch->rows()->where('status', 'valid')->orderBy('row_number')->get();

        $groups = [];
        foreach ($validRows as $row) {
            $ref = trim((string) ($row->external_ref ?? ''));
            if ($ref === '') {
                $ref = '_no_ref_'.($row->id);
            }
            $groups[$ref][] = $row;
        }

        foreach ($groups as $rows) {
            $this->commitGroup($rows, $results);
        }

        return $results;
    }

    /**
     * @param  array<int, ImportRow>  $rows
     * @param  array<int, array{status: string, document_id: ?int, document_type: ?string, error: ?string}>  $results
     */
    private function commitGroup(array $rows, array &$results): void
    {
        $first = $rows[0];
        $normalized = (array) ($first->normalized ?? []);

        $journalDate = $this->normalizeDate(trim((string) ($normalized['journal_date'] ?? '')));
        $description = trim((string) ($normalized['description'] ?? ''));

        // Bangun lines dari setiap baris.
        $lines = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($rows as $i => $row) {
            $n = (array) ($row->normalized ?? []);
            $accountCode = trim((string) ($n['account_code'] ?? ''));
            $debit = (float) ($n['debit'] ?? 0);
            $credit = (float) ($n['credit'] ?? 0);
            $deptCode = trim((string) ($n['department'] ?? ''));
            $projCode = trim((string) ($n['project'] ?? ''));

            $totalDebit += $debit;
            $totalCredit += $credit;

            $departmentId = null;
            if ($deptCode !== '') {
                $departmentId = Department::query()->where('code', $deptCode)->value('id');
            }

            $projectId = null;
            if ($projCode !== '') {
                $projectId = Project::query()->where('code', $projCode)->value('id');
            }

            $lines[] = [
                'account_id' => $this->accountId($accountCode),
                'description' => trim((string) ($n['description'] ?? '')) !== ''
                    ? trim((string) ($n['description'] ?? ''))
                    : 'Baris impor '.($row->row_number),
                'debit' => $debit,
                'credit' => $credit,
                'department_id' => $departmentId,
                'project_id' => $projectId,
                'line_order' => $i,
            ];
        }

        // Validasi keseimbangan debit-kredit di level group sebelum commit.
        if (abs($totalDebit - $totalCredit) > 0.001) {
            $this->markGroupFailed($rows, $results, sprintf(
                'Jurnal tidak seimbang: total Debit %.2f ≠ total Credit %.2f.',
                $totalDebit,
                $totalCredit
            ));

            return;
        }

        // Min 2 baris — syarat JournalEntryService::createManual().
        if (count($lines) < 2) {
            $this->markGroupFailed($rows, $results, 'Jurnal umum memerlukan minimal 2 baris (debit dan kredit).');

            return;
        }

        try {
            $journal = $this->journalService->createManual([
                'journal_date' => $journalDate,
                'description' => $description !== '' ? $description : null,
                'lines' => $lines,
            ], ['suppress_auto_post' => true]);

            foreach ($rows as $row) {
                $results[$row->id] = [
                    'status' => 'committed',
                    'document_id' => $journal->id,
                    'document_type' => JournalEntry::class,
                    'error' => null,
                ];
            }
        } catch (ApiException $e) {
            $this->markGroupFailed($rows, $results, $e->getMessage());
        } catch (Throwable $e) {
            $this->markGroupFailed($rows, $results, 'Gagal membuat jurnal: '.$e->getMessage());
        }
    }

    private function accountId(string $code): int
    {
        return (int) ChartOfAccount::query()->where('account_code', $code)->value('id');
    }

    /**
     * @param  array<int, ImportRow>  $rows
     * @param  array<int, array{status: string, document_id: ?int, document_type: ?string, error: ?string}>  $results
     */
    private function markGroupFailed(array $rows, array &$results, string $message): void
    {
        foreach ($rows as $row) {
            $results[$row->id] = [
                'status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => $message,
            ];
        }
    }
}
