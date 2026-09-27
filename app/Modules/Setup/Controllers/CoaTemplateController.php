<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Imports\Services\ImportTemplateService;
use App\Modules\Setup\Requests\ApplyCoaTemplateRequest;
use App\Modules\Setup\Requests\ImportCoaFileRequest;
use App\Modules\Setup\Services\CoaTemplateService;
use App\Shared\Api\ApiResponse;
use App\Shared\Export\ExcelExportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CoaTemplateController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CoaTemplateService $service) {}

    public function index(): JsonResponse
    {
        return $this->successResponse($this->service->templates(), 'COA templates retrieved successfully');
    }

    public function apply(ApplyCoaTemplateRequest $request): JsonResponse
    {
        $accounts = $this->service->applyTemplate(
            (string) $request->validated('template_id'),
            (array) $request->validated('accounts'),
        );

        return $this->successResponse($accounts, 'COA template applied successfully');
    }

    /**
     * Baca berkas COA milik user (CSV/XLSX) jadi draft akun -- BELUM disimpan.
     * Hasilnya dipratinjau/diedit di wizard lalu dikirim lewat `apply()` yang
     * sudah ada, sama seperti draft dari input manual.
     */
    public function import(ImportCoaFileRequest $request): JsonResponse
    {
        $result = $this->service->importFromFile($request->file('file'));

        return $this->successResponse($result, 'COA file parsed successfully');
    }

    /**
     * Templat unduhan kolom Code/Name/Type/Parent Code/Cash-Bank -- memakai
     * generator templat impor umum (profil `chart_of_account`) supaya
     * kolomnya selalu sejajar dengan yang dibaca `import()` di atas.
     */
    public function importTemplate(ImportTemplateService $templates, ExcelExportService $excel): StreamedResponse
    {
        return $excel->downloadTemplate($templates->template('chart_of_account'));
    }
}
