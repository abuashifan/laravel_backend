<?php

namespace App\Modules\Setup\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Requests\ApplyCoaTemplateRequest;
use App\Modules\Setup\Services\CoaTemplateService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

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
}
