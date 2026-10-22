<?php

namespace App\Modules\MasterData\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Imports\Requests\ListImportSheetsRequest;
use App\Modules\Imports\Services\ImportSheetLister;
use App\Modules\MasterData\Requests\ImportAccountMappingFileRequest;
use App\Modules\MasterData\Requests\UpdateAccountMappingRequest;
use App\Modules\MasterData\Services\AccountMappingStorageService;
use App\Shared\Api\ApiResponse;
use App\Shared\Export\ExcelExportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountMappingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AccountMappingStorageService $service) {}

    public function index(): JsonResponse
    {
        $this->service->syncDefaultMappingsFromConfig();
        $items = $this->service->list();

        return $this->successResponse($items, 'Account mappings retrieved successfully');
    }

    public function update(UpdateAccountMappingRequest $request, string $mappingKey): JsonResponse
    {
        $mapping = $this->service->updateMapping($mappingKey, $request->validated()['account_id'] ?? null);

        return $this->successResponse($mapping, 'Account mapping updated successfully');
    }

    /**
     * Tombol "Reset & Scan Ulang COA": timpa seluruh pemetaan dengan akun
     * default yang ditemukan di COA saat ini -- lihat
     * `AccountMappingStorageService::resetMappingsToDefaults()`.
     */
    public function resetDefaults(): JsonResponse
    {
        $summary = $this->service->resetMappingsToDefaults();

        return $this->successResponse([
            'summary' => $summary,
            'mappings' => $this->service->list(),
        ], 'Account mappings reset to defaults');
    }

    /**
     * Terapkan berkas Mapping Key + Account Code lewat `updateMapping()` yang
     * sudah ada -- dipakai ulang di halaman Pengaturan maupun Step 3 wizard
     * setup (keduanya sudah memakai endpoint/komponen yang sama).
     */
    public function import(ImportAccountMappingFileRequest $request): JsonResponse
    {
        $result = $this->service->importFromFile($request->file('file'), $request->validated('sheet'));

        return $this->successResponse($result, 'Account mapping file parsed successfully');
    }

    /**
     * Daftar sheet berkas yang baru dipilih, supaya layar impor bisa menawarkan
     * pilihan sheet sebelum berkasnya dibaca. Berkas CSV mengembalikan daftar kosong.
     */
    public function importSheets(ListImportSheetsRequest $request, ImportSheetLister $lister): JsonResponse
    {
        return $this->successResponse($lister->list($request->file('file')), 'Import sheets retrieved.');
    }

    /**
     * Templat berisi SELURUH mapping key yang terdaftar saat ini (bukan
     * contoh statis) -- lihat `AccountMappingStorageService::importTemplate()`.
     */
    public function importTemplate(ExcelExportService $excel): StreamedResponse
    {
        return $excel->downloadTemplate($this->service->importTemplate());
    }
}
