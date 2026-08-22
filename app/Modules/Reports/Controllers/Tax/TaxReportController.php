<?php

namespace App\Modules\Reports\Controllers\Tax;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\Tax\InputVatReportRequest;
use App\Modules\Reports\Requests\Tax\OutputVatReportRequest;
use App\Modules\Reports\Services\Tax\InputVatReportService;
use App\Modules\Reports\Services\Tax\OutputVatReportService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class TaxReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OutputVatReportService $outputVatService,
        private readonly InputVatReportService $inputVatService,
    ) {}

    public function outputVat(OutputVatReportRequest $request): JsonResponse
    {
        return $this->successResponse($this->outputVatService->getReport($request->validated()), 'Output VAT report retrieved successfully');
    }

    public function inputVat(InputVatReportRequest $request): JsonResponse
    {
        return $this->successResponse($this->inputVatService->getReport($request->validated()), 'Input VAT report retrieved successfully');
    }
}
