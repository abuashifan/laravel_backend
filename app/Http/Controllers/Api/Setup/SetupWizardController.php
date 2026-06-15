<?php

namespace App\Http\Controllers\Api\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\ReopenSetupRequest;
use App\Http\Requests\Setup\UpdateSetupCurrentStepRequest;
use App\Http\Requests\Setup\ValidateSetupStepRequest;
use App\Services\Setup\SetupWizardService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class SetupWizardController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SetupWizardService $service)
    {
    }

    public function status(): JsonResponse
    {
        return $this->successResponse($this->service->status(), 'Setup status retrieved successfully');
    }

    public function steps(): JsonResponse
    {
        return $this->successResponse($this->service->steps(), 'Setup steps retrieved successfully');
    }

    public function updateCurrentStep(UpdateSetupCurrentStepRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->updateCurrentStep($request->validated()), 'Setup current step updated successfully');
    }

    public function validateStep(ValidateSetupStepRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->validateStep($request->validated()), 'Setup step validated successfully');
    }

    public function validateAll(): JsonResponse
    {
        return $this->successResponse($this->service->validateAll(), 'Setup validation completed successfully');
    }

    public function openingBalancePreview(): JsonResponse
    {
        return $this->successResponse($this->service->openingBalancePreview(), 'Setup opening balance preview retrieved successfully');
    }

    public function finalize(): JsonResponse
    {
        return $this->successResponse($this->service->finalize(), 'Setup finalized successfully');
    }

    public function reopen(ReopenSetupRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->reopen((string) $request->validated('reason')), 'Setup reopened successfully');
    }
}
