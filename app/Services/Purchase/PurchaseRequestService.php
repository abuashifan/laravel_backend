<?php

namespace App\Services\Purchase;

use App\Exceptions\ApiException;
use App\Models\Tenant\PurchaseRequest;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Purchase\Concerns\HandlesPurchaseDocuments;
use App\Services\Tenant\TenantContext;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseRequestService
{
    use HandlesPurchaseDocuments;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly ?AuditLogService $auditLogService = null,
    ) {
    }

    public function list(array $filters = []): Collection
    {
        $query = PurchaseRequest::query()->with('department', 'project');

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', (int) $filters['department_id']);
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', (int) $filters['project_id']);
        }

        return $query->orderByDesc('request_date')->orderByDesc('id')->get();
    }

    public function find(int $id): PurchaseRequest
    {
        return PurchaseRequest::query()
            ->with('lines.product', 'lines.unit', 'department', 'project', 'requester')
            ->findOrFail($id);
    }

    public function create(array $data): PurchaseRequest
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($company, $data) {
            $number = $this->documentNumberService->generate($company, DocumentType::PURCHASE_REQUEST, (string) $data['request_date']);
            $lines = $this->normalizeRequestLines((array) $data['lines']);

            $request = PurchaseRequest::query()->create(array_merge($this->guardedPurchaseHeader($data), [
                'request_number' => $number,
                'status' => 'draft',
                'estimated_total' => $this->estimatedTotal($lines),
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]));

            $request->lines()->createMany($lines);
            $request = $request->refresh()->load('lines', 'department', 'project');
            $this->auditPurchase($this->auditLogService, 'purchase_request.created', $request, 'request_number');

            return $request;
        });
    }

    public function update(PurchaseRequest $request, array $data): PurchaseRequest
    {
        if (! in_array($request->status, ['draft'], true)) {
            throw ApiException::make('PURCHASE_REQUEST_NOT_EDITABLE', 'Purchase request status is not editable.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($request, $data) {
            $existingLines = $request->lines()->get()->map(fn ($line): array => [
                'product_id' => $line->product_id,
                'product_code' => $line->product_code,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_id' => $line->unit_id,
                'estimated_unit_price' => $line->estimated_unit_price,
                'warehouse_id' => $line->warehouse_id,
                'department_id' => $line->department_id,
                'project_id' => $line->project_id,
                'source_line_type' => $line->source_line_type,
                'source_line_id' => $line->source_line_id,
                'sort_order' => $line->sort_order,
                'metadata' => $line->metadata,
            ])->all();
            $lines = $this->normalizeRequestLines((array) ($data['lines'] ?? $existingLines));

            $request->fill(array_merge($this->guardedPurchaseHeader($data), [
                'estimated_total' => $this->estimatedTotal($lines),
                'updated_by' => auth()->id(),
                'revision_no' => (int) $request->revision_no + 1,
            ]));
            $request->save();
            $request->lines()->delete();
            $request->lines()->createMany($lines);
            $request = $request->refresh()->load('lines', 'department', 'project');
            $this->auditPurchase($this->auditLogService, 'purchase_request.updated', $request, 'request_number');

            return $request;
        });
    }

    public function submit(PurchaseRequest $request): PurchaseRequest
    {
        return $this->transition($request, 'submitted', 'submitted_by', 'submitted_at', ['draft']);
    }

    public function approve(PurchaseRequest $request): PurchaseRequest
    {
        return $this->transition($request, 'approved', 'approved_by', 'approved_at', ['submitted']);
    }

    public function reject(PurchaseRequest $request, ?string $reason = null): PurchaseRequest
    {
        $request->reject_reason = $reason;
        return $this->transition($request, 'rejected', 'rejected_by', 'rejected_at', ['submitted']);
    }

    public function cancel(PurchaseRequest $request, ?string $reason = null): PurchaseRequest
    {
        $request->cancel_reason = $reason;
        return $this->transition($request, 'cancelled', 'cancelled_by', 'cancelled_at', ['draft', 'submitted', 'approved']);
    }

    public function markConverted(PurchaseRequest $request): PurchaseRequest
    {
        return $this->transition($request, 'converted', 'converted_by', 'converted_at', ['approved']);
    }

    private function transition(PurchaseRequest $request, string $status, string $userField, string $dateField, array $from): PurchaseRequest
    {
        if (! in_array($request->status, $from, true)) {
            throw ApiException::make('INVALID_PURCHASE_REQUEST_STATUS', 'Invalid purchase request status transition.', 422);
        }

        $request->status = $status;
        $request->{$userField} = auth()->id();
        $request->{$dateField} = now();
        $request->save();
        $request = $request->refresh()->load('lines', 'department', 'project');
        $this->auditPurchase($this->auditLogService, 'purchase_request.'.$status, $request, 'request_number');

        return $request;
    }

    private function estimatedTotal(array $lines): float
    {
        return round(array_reduce(
            $lines,
            fn (float $carry, array $line): float => $carry + (float) ($line['estimated_line_total'] ?? 0),
            0.0
        ), 2, PHP_ROUND_HALF_UP);
    }
}
