<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesQuotation;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Sales\Concerns\HandlesSalesDocuments;
use App\Services\Tenant\TenantContext;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SalesOrderService
{
    use HandlesSalesDocuments;

    public function __construct(private readonly TenantContext $tenantContext, private readonly DocumentNumberService $documentNumberService, private readonly SalesCalculationService $calculationService, private readonly SalesQuotationService $quotationService, private readonly CustomerDepositService $depositService, private readonly ?AuditLogService $auditLogService = null) {}

    public function list(array $filters = []): Collection
    {
        $query = SalesOrder::query()->with('customer');
        if (! empty($filters['status'])) $query->where('status', (string) $filters['status']);
        return $query->orderByDesc('order_date')->orderByDesc('id')->get();
    }

    public function find(int $id): SalesOrder
    {
        return SalesOrder::query()->with('lines.product', 'customer', 'quotation', 'deposits')->findOrFail($id);
    }

    public function create(array $data): SalesOrder
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        $this->ensureCustomerExists((int) $data['customer_id']);

        return DB::connection('tenant')->transaction(function () use ($company, $data) {
            $lines = $this->normalizeLines((array) $data['lines'], fn (array $line): array => ['quotation_line_id' => $line['quotation_line_id'] ?? null]);
            $totals = $this->calculationService->calculateDocument($lines, $data);
            $headerTotals = $totals; unset($headerTotals['lines']);
            $order = SalesOrder::query()->create(array_merge($this->guardedForHeader($data), $headerTotals, [
                'order_number' => $this->documentNumberService->generate($company, DocumentType::SALES_ORDER, (string) $data['order_date']),
                'status' => 'draft',
                'has_down_payment' => (bool) ($data['has_down_payment'] ?? false),
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]));
            $order->lines()->createMany($totals['lines']);
            if ((bool) ($data['has_down_payment'] ?? false) && ! empty($data['down_payment'])) $this->depositService->createFromSalesOrder($order->refresh(), (array) $data['down_payment']);
            return $order->refresh()->load('lines', 'customer', 'deposits');
        });
    }

    public function update(SalesOrder $order, array $data): SalesOrder
    {
        if (! in_array($order->status, ['draft', 'approved'], true)) throw ApiException::make('SALES_ORDER_NOT_EDITABLE', 'Sales order status is not editable.', 422);
        return DB::connection('tenant')->transaction(function () use ($order, $data) {
            $lines = $this->normalizeLines((array) ($data['lines'] ?? $order->lines()->get()->toArray()), fn (array $line): array => ['quotation_line_id' => $line['quotation_line_id'] ?? null]);
            $totals = $this->calculationService->calculateDocument($lines, array_merge($order->toArray(), $data));
            $headerTotals = $totals; unset($headerTotals['lines']);
            $order->fill(array_merge($this->guardedForHeader($data), $headerTotals, ['updated_by' => auth()->id(), 'revision_no' => (int) $order->revision_no + 1]))->save();
            $order->lines()->delete(); $order->lines()->createMany($totals['lines']);
            return $order->refresh()->load('lines', 'customer', 'deposits');
        });
    }

    public function createFromQuotation(SalesQuotation $quotation, array $overrides = []): SalesOrder
    {
        if ($quotation->status === 'converted') throw ApiException::make('QUOTATION_ALREADY_CONVERTED', 'Quotation already converted.', 422);
        $quotation->loadMissing('lines');
        $order = $this->create(array_merge([
            'order_date' => now()->toDateString(), 'customer_id' => $quotation->customer_id, 'customer_address' => $quotation->customer_address,
            'quotation_id' => $quotation->id, 'quotation_number' => $quotation->quotation_number, 'salesperson_id' => $quotation->salesperson_id,
            'currency_code' => $quotation->currency_code, 'exchange_rate' => $quotation->exchange_rate, 'is_taxable' => $quotation->is_taxable,
            'tax_included' => $quotation->tax_included, 'header_discount_type' => $quotation->header_discount_type, 'header_discount_value' => $quotation->header_discount_value,
            'source_type' => 'sales_quotation', 'source_id' => $quotation->id, 'source_number' => $quotation->quotation_number, 'source_revision' => $quotation->revision_no,
            'lines' => $quotation->lines->map(fn ($line) => array_merge($line->only(['product_id','product_code','description','quantity','unit_id','unit_price','discount_type','discount_value','tax_id','tax_rate','warehouse_id','department_id','project_id','sort_order','metadata']), ['quotation_line_id' => $line->id, 'source_line_type' => 'sales_quotation_line', 'source_line_id' => $line->id]))->toArray(),
        ], $overrides));
        $this->quotationService->markConverted($quotation);
        return $order;
    }

    public function approve(SalesOrder $order): SalesOrder { return $this->transition($order, 'approved', 'approved_by', 'approved_at', ['draft']); }
    public function confirm(SalesOrder $order): SalesOrder { return $this->transition($order, 'confirmed', 'confirmed_by', 'confirmed_at', ['draft', 'approved']); }
    public function cancel(SalesOrder $order, ?string $reason = null): SalesOrder { $order->cancel_reason = $reason; return $this->transition($order, 'cancelled', 'cancelled_by', 'cancelled_at', ['draft', 'approved', 'confirmed']); }
    public function close(SalesOrder $order): SalesOrder { return $this->transition($order, 'closed', 'closed_by', 'closed_at', ['confirmed', 'delivered', 'invoiced']); }
    public function refreshDeliveryStatus(SalesOrder $order): SalesOrder { $order->load('lines'); $total = (float) $order->lines->sum('quantity'); $delivered = (float) $order->lines->sum('delivered_quantity'); if ($delivered > 0) { $order->status = $delivered >= $total ? 'delivered' : 'partially_delivered'; $order->save(); } return $order->refresh(); }

    private function transition(SalesOrder $order, string $status, string $userField, string $dateField, array $from): SalesOrder
    {
        if (! in_array($order->status, $from, true)) throw ApiException::make('INVALID_SALES_ORDER_STATUS', 'Invalid sales order status transition.', 422);
        $order->status = $status; $order->{$userField} = auth()->id(); $order->{$dateField} = now(); $order->save();
        return $order->refresh()->load('lines', 'customer', 'deposits');
    }
}
