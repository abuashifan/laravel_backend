<?php

namespace App\Modules\MasterData\Controllers;

use App\Http\Controllers\Controller;
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
     * Terapkan berkas Mapping Key + Account Code lewat `updateMapping()` yang
     * sudah ada -- dipakai ulang di halaman Pengaturan maupun Step 3 wizard
     * setup (keduanya sudah memakai endpoint/komponen yang sama).
     */
    public function import(ImportAccountMappingFileRequest $request): JsonResponse
    {
        $result = $this->service->importFromFile($request->file('file'));

        return $this->successResponse($result, 'Account mapping file parsed successfully');
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
