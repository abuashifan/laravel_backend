<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\Tenant\BillingInvoice;
use App\Models\Tenant\SalesInvoice;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Sales\Concerns\HandlesSalesDocuments;
use App\Services\Tenant\TenantContext;
use App\Services\Transactions\PaymentTermDueDateService;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BillingInvoiceService
{
    use HandlesSalesDocuments;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly PaymentTermDueDateService $paymentTermDueDateService,
        private readonly ?AuditLogService $auditLogService = null
    ) {}

    public function list(array $filters = []): Collection
    {
        $query = BillingInvoice::query()->with('customer', 'paymentTerm', 'salesInvoice');
        if (! empty($filters['status'])) $query->where('status', (string) $filters['status']);
        return $query->orderByDesc('billing_date')->orderByDesc('id')->get();
    }

    public function find(int $id): BillingInvoice
    {
        return BillingInvoice::query()->with('lines', 'customer', 'paymentTerm', 'salesInvoice')->findOrFail($id);
    }

    public function create(array $data): BillingInvoice
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        $this->ensureCustomerExists((int) $data['customer_id']);
        $data = $this->paymentTermDueDateService->apply($data, 'billing_date', (int) $data['customer_id']);
        $amount = (float) array_sum(array_map(fn ($line) => (float) ($line['amount'] ?? 0), (array) $data['lines']));

        return DB::connection('tenant')->transaction(function () use ($company, $data, $amount) {
            $billing = BillingInvoice::query()->create(array_merge($this->guardedForHeader($data), [
                'billing_number' => $this->documentNumberService->generate($company, DocumentType::BILLING_INVOICE, (string) $data['billing_date']),
                'billing_amount' => $amount,
                'balance_due' => $amount,
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]));
            $billing->lines()->createMany($this->normalizeLines((array) $data['lines']));
            $this->auditSales($this->auditLogService, 'billing_invoice.created', 'sales', $billing, 'billing_number');
            return $billing->refresh()->load('lines', 'customer', 'paymentTerm', 'salesInvoice');
        });
    }

    public function createFromSalesInvoice(SalesInvoice $invoice, array $overrides = []): BillingInvoice
    {
        $invoice->loadMissing('lines');
        return $this->create(array_merge([
            'billing_date' => now()->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'payment_term_id' => $invoice->payment_term_id,
            'customer_id' => $invoice->customer_id,
            'sales_invoice_id' => $invoice->id,
            'sales_invoice_number' => $invoice->invoice_number,
            'source_type' => 'sales_invoice',
            'source_id' => $invoice->id,
            'source_number' => $invoice->invoice_number,
            'source_revision' => $invoice->revision_no,
            'lines' => $invoice->lines->map(fn ($line) => [
                'sales_invoice_line_id' => $line->id,
                'description' => $line->description,
                'amount' => $line->line_total,
                'source_line_type' => 'sales_invoice_line',
                'source_line_id' => $line->id,
                'sort_order' => $line->sort_order,
            ])->toArray(),
        ], $overrides));
    }

    public function issue(BillingInvoice $billing): BillingInvoice
    {
        if ($billing->status !== 'draft') throw ApiException::make('INVALID_BILLING_STATUS', 'Invalid billing status transition.', 422);
        $billing->status = 'issued';
        $billing->issued_by = auth()->id();
        $billing->issued_at = now();
        $billing->save();
        return $billing->refresh()->load('lines', 'customer', 'paymentTerm', 'salesInvoice');
    }

    public function cancel(BillingInvoice $billing, ?string $reason = null): BillingInvoice
    {
        if (! in_array($billing->status, ['draft', 'issued'], true)) throw ApiException::make('INVALID_BILLING_STATUS', 'Invalid billing status transition.', 422);
        $billing->status = 'cancelled';
        $billing->cancel_reason = $reason;
        $billing->cancelled_by = auth()->id();
        $billing->cancelled_at = now();
        $billing->save();
        return $billing->refresh()->load('lines', 'customer', 'paymentTerm', 'salesInvoice');
    }

    private function normalizeLines(array $lines): array
    {
        return array_values(array_map(fn (array $line, int $index): array => [
            'sales_invoice_line_id' => $line['sales_invoice_line_id'] ?? null,
            'description' => $line['description'],
            'amount' => (float) $line['amount'],
            'source_line_type' => $line['source_line_type'] ?? null,
            'source_line_id' => $line['source_line_id'] ?? null,
            'sort_order' => $line['sort_order'] ?? $index,
            'metadata' => $line['metadata'] ?? null,
        ], $lines, array_keys($lines)));
    }
}
