<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\Tenant\SalesQuotation;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Sales\Concerns\HandlesSalesDocuments;
use App\Services\Tenant\TenantContext;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SalesQuotationService
{
    use HandlesSalesDocuments;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly SalesCalculationService $calculationService,
        private readonly ?AuditLogService $auditLogService = null,
    ) {
    }

    public function list(array $filters = []): Collection
    {
        $query = SalesQuotation::query()->with('customer');

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        return $query->orderByDesc('quotation_date')->orderByDesc('id')->get();
    }

    public function find(int $id): SalesQuotation
    {
        return SalesQuotation::query()->with('lines.product', 'customer')->findOrFail($id);
    }

    public function create(array $data): SalesQuotation
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        $this->ensureCustomerExists((int) $data['customer_id']);

        return DB::connection('tenant')->transaction(function () use ($company, $data) {
            $number = $this->documentNumberService->generate($company, DocumentType::SALES_QUOTATION, (string) $data['quotation_date']);
            $lines = $this->normalizeLines((array) $data['lines']);
            $totals = $this->calculationService->calculateDocument($lines, $data);
            $headerTotals = $totals;
            unset($headerTotals['lines']);

            $quotation = SalesQuotation::query()->create(array_merge($this->guardedForHeader($data), $headerTotals, [
                'quotation_number' => $number,
                'status' => 'draft',
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]));

            $quotation->lines()->createMany($totals['lines']);
            $quotation = $quotation->refresh()->load('lines', 'customer');
            $this->auditSales($this->auditLogService, 'sales_quotation.created', 'sales', $quotation, 'quotation_number');

            return $quotation;
        });
    }

    public function update(SalesQuotation $quotation, array $data): SalesQuotation
    {
        if (in_array($quotation->status, ['cancelled', 'rejected', 'expired', 'converted'], true)) {
            throw ApiException::make('SALES_QUOTATION_NOT_EDITABLE', 'Quotation status is not editable.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($quotation, $data) {
            $lines = $this->normalizeLines((array) ($data['lines'] ?? $quotation->lines()->get()->toArray()));
            $header = array_merge($quotation->toArray(), $data);
            $totals = $this->calculationService->calculateDocument($lines, $header);
            $headerTotals = $totals;
            unset($headerTotals['lines']);

            $quotation->fill(array_merge($this->guardedForHeader($data), $headerTotals, [
                'updated_by' => auth()->id(),
                'revision_no' => (int) $quotation->revision_no + 1,
            ]));
            $quotation->save();
            $quotation->lines()->delete();
            $quotation->lines()->createMany($totals['lines']);
            $quotation = $quotation->refresh()->load('lines', 'customer');
            $this->auditSales($this->auditLogService, 'sales_quotation.updated', 'sales', $quotation, 'quotation_number');

            return $quotation;
        });
    }

    public function send(SalesQuotation $quotation): SalesQuotation
    {
        return $this->transition($quotation, 'sent', 'sent_by', 'sent_at', ['draft']);
    }

    public function approve(SalesQuotation $quotation): SalesQuotation
    {
        return $this->transition($quotation, 'approved', 'approved_by', 'approved_at', ['draft', 'sent']);
    }

    public function accept(SalesQuotation $quotation): SalesQuotation
    {
        return $this->transition($quotation, 'accepted', 'accepted_by', 'accepted_at', ['sent', 'approved']);
    }

    public function reject(SalesQuotation $quotation, ?string $reason = null): SalesQuotation
    {
        $quotation = $this->transition($quotation, 'rejected', 'rejected_by', 'rejected_at', ['sent', 'approved']);
        if ($reason !== null) {
            $quotation->internal_notes = trim($quotation->internal_notes."\n".$reason);
            $quotation->save();
        }

        return $quotation->refresh();
    }

    public function cancel(SalesQuotation $quotation, ?string $reason = null): SalesQuotation
    {
        $quotation->cancel_reason = $reason;
        return $this->transition($quotation, 'cancelled', 'cancelled_by', 'cancelled_at', ['draft', 'sent', 'approved', 'accepted']);
    }

    public function markConverted(SalesQuotation $quotation): SalesQuotation
    {
        return $this->transition($quotation, 'converted', 'converted_by', 'converted_at', ['accepted', 'approved', 'sent', 'draft']);
    }

    private function transition(SalesQuotation $quotation, string $status, string $userField, string $dateField, array $from): SalesQuotation
    {
        if (! in_array($quotation->status, $from, true)) {
            throw ApiException::make('INVALID_SALES_QUOTATION_STATUS', 'Invalid quotation status transition.', 422);
        }

        $quotation->status = $status;
        $quotation->{$userField} = auth()->id();
        $quotation->{$dateField} = now();
        $quotation->save();
        $quotation = $quotation->refresh()->load('lines', 'customer');
        $this->auditSales($this->auditLogService, 'sales_quotation.'.$status, 'sales', $quotation, 'quotation_number');

        return $quotation;
    }
}
