<?php

namespace App\Modules\Reports\Controllers\Tax;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\Tax\EfakturExportRequest;
use App\Modules\Reports\Services\Tax\EfakturPurchaseExportService;
use App\Modules\Reports\Services\Tax\EfakturSalesExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor E-Faktur DJP dalam format CSV siap-unduh (Fase 12).
 *
 * Endpoint mengembalikan streamed download (Content-Type text/csv) — bukan JSON —
 * karena format DJP ketat dan file langsung diimpor ke aplikasi e-Faktur.
 */
class EfakturExportController extends Controller
{
    public function __construct(
        private readonly EfakturSalesExportService $salesService,
        private readonly EfakturPurchaseExportService $purchaseService,
    ) {}

    public function sales(EfakturExportRequest $request): StreamedResponse
    {
        return $this->stream($this->salesService->export($request->validated()));
    }

    public function purchase(EfakturExportRequest $request): StreamedResponse
    {
        return $this->stream($this->purchaseService->export($request->validated()));
    }

    /**
     * @param  array{filename:string, headers:list<string>, rows:list<list<string>>}  $data
     */
    private function stream(array $data): StreamedResponse
    {
        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $data['headers']);
            foreach ($data['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $data['filename'], [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
