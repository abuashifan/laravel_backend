<?php

namespace App\Http\Controllers\Api\Transactions;

use App\Http\Controllers\Controller;
use App\Services\Transactions\SourceDocumentPickerService;
use App\Support\Api\ApiResponseBuilder;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SourceDocumentPickerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SourceDocumentPickerService $service) {}

    public function availability(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'target_type' => ['required', 'string'],
            'source_type' => ['nullable', 'string'],
            'partner_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'vendor_id' => ['nullable', 'integer'],
        ]);

        return $this->successResponse($this->service->availability($filters), 'Source document availability retrieved successfully');
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'target_type' => ['required', 'string'],
            'source_type' => ['nullable', 'string'],
            'partner_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'vendor_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $documents = $this->service->list($filters);

        return ApiResponseBuilder::success($documents, 'Source documents retrieved successfully');
    }
}
