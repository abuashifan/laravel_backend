<?php

namespace App\Modules\Imports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Requests\StoreImportRequest;
use App\Modules\Imports\Requests\UpdateImportMappingRequest;
use App\Modules\Imports\Services\DuplicateImportFileException;
use App\Modules\Imports\Services\ImportBatchService;
use App\Modules\Imports\Services\ImportTemplateService;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Api\ApiResponse;
use App\Shared\Exceptions\ApiException;
use App\Shared\Export\ExcelExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ImportBatchService $batches,
        private readonly ImportTemplateService $templates,
        private readonly ExcelExportService $excel,
    ) {}

    /**
     * Metadata seluruh profil impor — label, kunci field (sejajar dengan
     * `headers`), field wajib. Frontend memakainya membangun `column_map`
     * otomatis saat client memakai templat unduhan apa adanya, dan menyusun
     * layar pemetaan manual saat tidak.
     */
    public function profiles(): JsonResponse
    {
        $profiles = collect((array) config('imports.profiles', []))
            ->map(fn (array $profile, string $key) => [
                'key' => $key,
                'label' => $profile['label'],
                'fields' => $profile['fields'] ?? [],
                'headers' => $profile['headers'],
                'required_fields' => $profile['required_fields'],
            ])
            ->values();

        return $this->successResponse($profiles, 'Import profiles retrieved.');
    }

    /**
     * Upload jalur master data — hanya menerima profil master.
     */
    public function storeMaster(StoreImportRequest $request): JsonResponse
    {
        $profile = (string) $request->validated('profile');
        $this->assertProfileIn($profile, ['contact', 'product', 'chart_of_account', 'opening_balance', 'fixed_asset_opening'], 'master');

        return $this->store($request);
    }

    /**
     * Upload jalur transaksi — hanya menerima profil transaksi.
     */
    public function storeTransaction(StoreImportRequest $request): JsonResponse
    {
        $profile = (string) $request->validated('profile');
        $this->assertProfileIn($profile, ['sales_invoice', 'vendor_bill', 'journal_entry'], 'transactions');

        return $this->store($request);
    }

    public function store(StoreImportRequest $request): JsonResponse
    {
        try {
            $result = $this->batches->upload(
                (string) $request->validated('profile'),
                $request->file('file'),
                (bool) ($request->validated('confirm_duplicate_file') ?? false)
            );
        } catch (DuplicateImportFileException $exception) {
            return $this->warningResponse(
                ApiErrorCode::IMPORT_FILE_DUPLICATE,
                'Berkas ini sudah pernah diunggah. Konfirmasi untuk melanjutkan.',
                [],
                ['duplicate' => $exception->duplicate]
            );
        }

        return $this->successResponse($result, 'Import batch created.', 201);
    }

    public function mapping(UpdateImportMappingRequest $request, string $uuid): JsonResponse
    {
        return $this->successResponse(
            $this->batches->applyMapping($uuid, $request->validated('column_map')),
            'Import mapping saved and validated.'
        );
    }

    public function show(string $uuid): JsonResponse
    {
        return $this->successResponse($this->batches->show($uuid), 'Import batch retrieved.');
    }

    public function rows(Request $request, string $uuid): JsonResponse
    {
        return $this->listResponse($this->batches->rows($uuid, $request->query()), $request, 'Import rows retrieved.');
    }

    public function commit(string $uuid): JsonResponse
    {
        return $this->successResponse($this->batches->commit($uuid), 'Import batch committed.');
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->batches->cancel($uuid);

        return $this->successResponse(null, 'Import batch cancelled.');
    }

    private function assertProfileIn(string $profile, array $allowed, string $group): void
    {
        if (! in_array($profile, $allowed, true)) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                "Profil '{$profile}' tidak termasuk dalam grup impor {$group}.",
                422,
                ['profile' => ["Profil '{$profile}' bukan profil {$group}."]]
            );
        }
    }

    /**
     * Ekspor baris gagal dari batch impor ke Excel — supaya user bisa
     * memperbaiki lalu mengunggah ulang.
     */
    public function exportErrors(string $uuid, ExcelExportService $excel): StreamedResponse
    {
        $batch = ImportBatch::query()->where('uuid', $uuid)->firstOrFail();

        $rows = $batch->rows()
            ->where('status', 'invalid')
            ->orderBy('row_number')
            ->get();

        if ($rows->isEmpty()) {
            // Return empty Excel dengan header saja.
            return $excel->downloadFromArray([], ['Row', 'Errors'], "import-{$uuid}-errors");
        }

        // Ambil header dari kolom pertama yang punya normalized data.
        $firstRow = $rows->first();
        $normalized = (array) ($firstRow->normalized ?? []);
        $headers = array_keys($normalized);
        $headers[] = '_errors';

        $data = [];
        foreach ($rows as $row) {
            $values = (array) ($row->normalized ?? []);
            $errorMessages = [];
            foreach ((array) ($row->errors ?? []) as $field => $msgs) {
                $errorMessages[] = "{$field}: ".implode('; ', (array) $msgs);
            }
            $values['_errors'] = implode(' | ', $errorMessages);
            $data[] = array_values($values);
        }

        return $excel->downloadFromArray($data, $headers, "import-{$uuid}-errors");
    }

    /**
     * Templat diunduh sebagai .xlsx, bukan CSV: berkasnya langsung terbuka di
     * Excel tanpa dialog impor teks, dan pembaca unggahan sudah menerima
     * .xlsx (`SpreadsheetReaderFactory`) jadi berkas yang sama bisa diisi lalu
     * dikirim balik apa adanya. CSV tetap boleh diunggah -- yang berubah cuma
     * format unduhannya.
     *
     * Berkasnya berisi dua sheet: "Data" untuk diisi, dan "Referensi" berisi
     * master data yang harus dicocokkan (kategori aset, kode akun, departemen,
     * proyek) yang sekaligus jadi sumber dropdown di sheet Data. Lihat
     * `ExcelExportService::downloadTemplate()`.
     */
    public function template(string $profile): StreamedResponse
    {
        return $this->excel->downloadTemplate($this->templates->template($profile));
    }
}
