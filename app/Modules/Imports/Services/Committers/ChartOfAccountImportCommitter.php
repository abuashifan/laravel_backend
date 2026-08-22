<?php

namespace App\Modules\Imports\Services\Committers;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Services\Committers\Concerns\DetectsDuplicateCodesInBatch;
use App\Modules\Imports\Services\SpreadsheetReaderFactory;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Services\ChartOfAccountService;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Paling rawan dari tiga profil Fase 1 (rencana impor data): COA punya
 * hierarki induk-anak, dan urutan baris di berkas TIDAK BOLEH dipercaya —
 * importer mengurutkan sendiri lewat resolusi bertahap di `commit()`.
 *
 * Kebijakan sengaja "hanya tambah, tolak yang sudah ada" — BUKAN mode
 * perbarui. Menimpa akun yang sudah dipakai laporan bisa merusak angka yang
 * sudah benar; kalau nanti perlu "perbarui yang sudah ada", itu mode
 * terpisah yang dinyatakan eksplisit di layar, bukan default diam-diam.
 */
class ChartOfAccountImportCommitter implements ImportProfileCommitter
{
    use DetectsDuplicateCodesInBatch;

    private const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    /**
     * Kode akun (kolom `code`) yang muncul di SELURUH berkas, dimemo per
     * batch. Validasi berjalan baris demi baris seiring berkas dibaca
     * (`ImportBatchService::applyMapping()`), jadi baris yang anaknya
     * disebut LEBIH DULU dari induknya di berkas belum bisa melihat induknya
     * lewat `ImportRow` yang sudah tersimpan (baris itu belum diproses).
     * "Anak sebelum induk tetap masuk" (rencana impor data, Fase 1) menuntut
     * validasi melihat SELURUH berkas, bukan cuma yang sudah lewat — jadi
     * berkasnya dibaca ulang sekali di sini, terpisah dari loop utama.
     *
     * @var array<int, array<string, true>>
     */
    private array $codesInFileCache = [];

    public function __construct(
        private readonly ChartOfAccountService $accounts,
        private readonly SpreadsheetReaderFactory $readerFactory,
    ) {}

    public function validateRow(ImportBatch $batch, array $normalized): array
    {
        $errors = [];

        $type = trim((string) ($normalized['type'] ?? ''));
        if ($type !== '' && ! in_array($type, self::TYPES, true)) {
            $errors['type'][] = sprintf('Type "%s" tidak dikenal. Pakai salah satu: %s.', $type, implode(', ', self::TYPES));
        }

        $code = trim((string) ($normalized['code'] ?? ''));
        if ($code !== '') {
            if (ChartOfAccount::query()->where('account_code', $code)->exists()) {
                $errors['code'][] = "Kode akun \"{$code}\" sudah ada. Impor hanya menambah akun baru, tidak menimpa yang sudah ada.";
            } elseif ($this->isCodeUsedElsewhereInBatch($batch, 'code', $code)) {
                $errors['code'][] = "Kode akun \"{$code}\" dipakai lebih dari sekali di berkas ini.";
            }
        }

        $parentCode = trim((string) ($normalized['parent_code'] ?? ''));
        if ($parentCode !== '') {
            if ($parentCode === $code && $code !== '') {
                $errors['parent_code'][] = 'Kode induk tidak boleh sama dengan kode akun itu sendiri.';
            } elseif (! ChartOfAccount::query()->where('account_code', $parentCode)->exists()
                && ! isset($this->codesInFile($batch)[mb_strtolower($parentCode)])) {
                $errors['parent_code'][] = "Kode induk \"{$parentCode}\" tidak ditemukan, baik di data yang sudah ada maupun di berkas ini.";
            }
        }

        return $errors;
    }

    /**
     * @return array<string, true> kode (huruf kecil) => true
     */
    private function codesInFile(ImportBatch $batch): array
    {
        if (isset($this->codesInFileCache[$batch->id])) {
            return $this->codesInFileCache[$batch->id];
        }

        $codeHeader = (string) ($batch->column_map['code'] ?? '');
        if ($codeHeader === '') {
            return $this->codesInFileCache[$batch->id] = [];
        }

        $path = Storage::disk('local')->path($batch->stored_path);
        $extension = strtolower(pathinfo($batch->stored_path, PATHINFO_EXTENSION));
        $reader = $this->readerFactory->make($extension);

        $codes = [];
        foreach ($reader->rows($path) as $raw) {
            $value = mb_strtolower(trim((string) ($raw[$codeHeader] ?? '')));
            if ($value !== '') {
                $codes[$value] = true;
            }
        }

        return $this->codesInFileCache[$batch->id] = $codes;
    }

    /**
     * Resolusi bertahap: proses baris yang induknya SUDAH ADA (di database
     * atau sudah dibuat di putaran sebelumnya), ulangi sampai tidak ada lagi
     * kemajuan. Baris yang tersisa setelah itu pasti bagian dari siklus atau
     * menunjuk induk yang tidak pernah benar-benar dibuat (mis. baris
     * induknya sendiri gagal karena alasan lain).
     */
    public function commit(ImportBatch $batch): array
    {
        $rows = $batch->rows()->where('status', 'valid')->orderBy('row_number')->get()
            ->keyBy(fn (ImportRow $row): string => trim((string) (($row->normalized['code'] ?? ''))));

        $results = [];
        $createdCodes = [];

        do {
            $progressed = false;

            foreach ($rows as $code => $row) {
                if (isset($results[$row->id])) {
                    continue;
                }

                $parentCode = trim((string) ($row->normalized['parent_code'] ?? ''));
                $parentReady = $parentCode === ''
                    || isset($createdCodes[$parentCode])
                    || ChartOfAccount::query()->where('account_code', $parentCode)->exists();

                if (! $parentReady) {
                    continue;
                }

                $results[$row->id] = $this->commitRow($row, $parentCode !== '' ? ($createdCodes[$parentCode] ?? null) : null);
                if ($results[$row->id]['status'] === 'committed' && $code !== '') {
                    $createdCodes[$code] = $results[$row->id]['document_id'];
                }
                $progressed = true;
            }
        } while ($progressed);

        // Sisa baris (siklus, atau induknya sendiri gagal dibuat) — ditandai
        // gagal secara eksplisit, bukan diam-diam terlewat dari ringkasan.
        foreach ($rows as $row) {
            if (! isset($results[$row->id])) {
                $results[$row->id] = [
                    'status' => 'failed',
                    'document_id' => null,
                    'document_type' => null,
                    'error' => 'Akun induk tidak pernah berhasil dibuat (siklus, atau induk gagal karena sebab lain).',
                ];
            }
        }

        return $results;
    }

    /**
     * @return array{status: string, document_id: ?int, document_type: ?string, error: ?string}
     */
    private function commitRow(ImportRow $row, ?int $parentAccountIdFromThisBatch): array
    {
        $normalized = (array) $row->normalized;
        $parentCode = trim((string) ($normalized['parent_code'] ?? ''));

        $parentAccountId = $parentAccountIdFromThisBatch
            ?? ($parentCode !== '' ? ChartOfAccount::query()->where('account_code', $parentCode)->value('id') : null);

        try {
            $account = $this->accounts->create([
                'account_code' => trim((string) $normalized['code']),
                'account_name' => trim((string) $normalized['name']),
                'account_type' => trim((string) $normalized['type']),
                'parent_account_id' => $parentAccountId,
                'is_cash_bank' => $this->toBool($normalized['cash_bank'] ?? null),
                'is_active' => true,
            ]);

            return ['status' => 'committed', 'document_id' => $account->id, 'document_type' => ChartOfAccount::class, 'error' => null];
        } catch (ApiException $exception) {
            return ['status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => $exception->getMessage()];
        } catch (Throwable $exception) {
            return ['status' => 'failed', 'document_id' => null, 'document_type' => null, 'error' => 'Gagal membuat akun: '.$exception->getMessage()];
        }
    }

    private function toBool(?string $value): bool
    {
        $value = mb_strtolower(trim((string) $value));

        return in_array($value, ['1', 'ya', 'yes', 'true', 'y'], true);
    }
}
