<?php

namespace App\Services\Transactions;

use App\Exceptions\ApiException;
use App\Models\Tenant\DeliveryOrder;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\ProformaInvoice;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseRequest;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesQuotation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SourceDocumentPickerService
{
    private const FLOWS = [
        'sales.orders' => [
            'sales_quotation' => [
                'model' => SalesQuotation::class,
                'lines' => 'lines',
                'number' => 'quotation_number',
                'date' => 'quotation_date',
                'partner' => 'customer_id',
                'statuses' => ['sent', 'approved', 'accepted'],
                'line_source' => 'sales_quotation_line',
            ],
        ],
        'sales.proformas' => [
            'sales_order' => [
                'model' => SalesOrder::class,
                'lines' => 'lines',
                'number' => 'order_number',
                'date' => 'order_date',
                'partner' => 'customer_id',
                'statuses' => ['approved', 'confirmed', 'partially_delivered', 'delivered'],
                'line_source' => 'sales_order_line',
            ],
        ],
        'sales.delivery-orders' => [
            'sales_order' => [
                'model' => SalesOrder::class,
                'lines' => 'lines',
                'number' => 'order_number',
                'date' => 'order_date',
                'partner' => 'customer_id',
                'statuses' => ['confirmed', 'partially_delivered'],
                'line_source' => 'sales_order_line',
                'remaining' => ['quantity', 'delivered_quantity'],
            ],
            'proforma_invoice' => [
                'model' => ProformaInvoice::class,
                'lines' => 'lines',
                'number' => 'proforma_number',
                'date' => 'proforma_date',
                'partner' => 'customer_id',
                'statuses' => ['issued', 'accepted'],
                'line_source' => 'proforma_invoice_line',
            ],
        ],
        'sales.invoices' => [
            'delivery_order' => [
                'model' => DeliveryOrder::class,
                'lines' => 'lines',
                'number' => 'delivery_number',
                'date' => 'delivery_date',
                'partner' => 'customer_id',
                'statuses' => ['delivered', 'partially_invoiced'],
                'line_source' => 'delivery_order_line',
                'remaining' => ['quantity', 'invoiced_quantity'],
                'with' => ['salesOrder', 'lines.salesOrderLine', 'lines.product', 'lines.unit'],
            ],
        ],
        'purchase.orders' => [
            'purchase_request' => [
                'model' => PurchaseRequest::class,
                'lines' => 'lines',
                'number' => 'request_number',
                'date' => 'request_date',
                'partner' => null,
                'statuses' => ['submitted', 'approved'],
                'line_source' => 'purchase_request_line',
                'price' => 'estimated_unit_price',
            ],
        ],
        'purchase.goods-receipts' => [
            'purchase_order' => [
                'model' => PurchaseOrder::class,
                'lines' => 'lines',
                'number' => 'order_number',
                'date' => 'order_date',
                'partner' => 'vendor_id',
                'statuses' => ['confirmed', 'partially_received'],
                'line_source' => 'purchase_order_line',
                'remaining' => ['quantity', 'received_quantity'],
            ],
        ],
        'purchase.bills' => [
            'goods_receipt' => [
                'model' => GoodsReceipt::class,
                'lines' => 'lines',
                'number' => 'receipt_number',
                'date' => 'receipt_date',
                'partner' => 'vendor_id',
                'statuses' => ['received', 'partially_billed'],
                'line_source' => 'goods_receipt_line',
                'remaining' => ['quantity', 'billed_quantity'],
                'with' => ['purchaseOrder', 'lines.purchaseOrderLine', 'lines.product', 'lines.unit'],
            ],
        ],
    ];

    public function availability(array $filters): array
    {
        [$targetType, $sourceType, $definition] = $this->resolve($filters);
        $count = $this->baseQuery($definition, $filters)
            ->get()
            ->filter(fn (Model $document): bool => $this->documentLines($document, $sourceType, $definition) !== [])
            ->count();

        return [
            'target_type' => $targetType,
            'source_type' => $sourceType,
            'available' => $count > 0,
            'count' => $count,
        ];
    }

    public function list(array $filters): LengthAwarePaginator
    {
        [$targetType, $sourceType, $definition] = $this->resolve($filters);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 10)));

        $documents = $this->baseQuery($definition, $filters)
            ->get()
            ->map(fn (Model $document): ?array => $this->documentPayload($document, $targetType, $sourceType, $definition))
            ->filter()
            ->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $documents->forPage($page, $perPage)->values(),
            $documents->count(),
            $perPage,
            $page,
        );
    }

    private function resolve(array $filters): array
    {
        $targetType = (string) ($filters['target_type'] ?? '');
        $sourceType = (string) ($filters['source_type'] ?? '');
        $target = self::FLOWS[$targetType] ?? null;

        if (! $target) {
            throw ApiException::make('SOURCE_TARGET_UNSUPPORTED', 'Source target is not supported.', 422);
        }

        if ($sourceType === '') {
            $sourceType = array_key_first($target);
        }

        $definition = $target[$sourceType] ?? null;
        if (! $definition) {
            throw ApiException::make('SOURCE_TYPE_UNSUPPORTED', 'Source type is not supported for this target.', 422);
        }

        return [$targetType, $sourceType, $definition];
    }

    private function baseQuery(array $definition, array $filters): Builder
    {
        /** @var class-string<Model> $model */
        $model = $definition['model'];
        $query = $model::query()->with(array_merge([$definition['lines']], (array) ($definition['with'] ?? [])));
        $partnerField = $definition['partner'] ?? null;
        $partnerId = $filters['partner_id'] ?? $filters['customer_id'] ?? $filters['vendor_id'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));

        if ($partnerField && $partnerId !== null && $partnerId !== '') {
            $query->where($partnerField, (int) $partnerId);
        }

        $query->whereIn('status', (array) $definition['statuses']);

        if ($search !== '') {
            $numberField = $definition['number'];
            $query->where(function (Builder $inner) use ($numberField, $search): void {
                $inner->where($numberField, 'like', '%'.$search.'%')
                    ->orWhere('source_number', 'like', '%'.$search.'%')
                    ->orWhere('notes', 'like', '%'.$search.'%');
            });
        }

        return $query->orderByDesc($definition['date'])->orderByDesc('id');
    }

    private function documentPayload(Model $document, string $targetType, string $sourceType, array $definition): ?array
    {
        $lines = $this->documentLines($document, $sourceType, $definition);
        if ($lines === []) {
            return null;
        }

        $numberField = $definition['number'];
        $dateField = $definition['date'];

        return [
            'id' => $document->getKey(),
            'target_type' => $targetType,
            'source_type' => $sourceType,
            'source_id' => $document->getKey(),
            'source_number' => $document->getAttribute($numberField),
            'source_revision' => $document->getAttribute('revision_no'),
            'document_number' => $document->getAttribute($numberField),
            'document_date' => $this->dateValue($document->getAttribute($dateField)),
            'status' => $document->getAttribute('status'),
            'partner_id' => $document->getAttribute($definition['partner'] ?? '') ?: null,
            'description' => $document->getAttribute('notes') ?: $document->getAttribute('source_number'),
            'header' => $this->headerPayload($document, $sourceType),
            'lines' => $lines,
        ];
    }

    private function documentLines(Model $document, string $sourceType, array $definition): array
    {
        return $document->getRelation($definition['lines'])
            ->map(function (Model $line, int $index) use ($sourceType, $definition): ?array {
                $quantity = $this->remainingQuantity($line, $definition);
                if ($quantity <= 0) {
                    return null;
                }

                return array_merge($line->only([
                    'product_id', 'product_code', 'description', 'unit_id', 'unit_price',
                    'discount_type', 'discount_value', 'tax_id', 'tax_rate', 'warehouse_id',
                    'department_id', 'project_id', 'expense_account_id', 'sort_order', 'metadata',
                ]), $this->sourceLinePayload($line, $sourceType), [
                    'id' => $line->getKey(),
                    'quantity' => $quantity,
                    'remaining_quantity' => $quantity,
                    'unit_price' => $this->unitPrice($line, $sourceType, $definition),
                    'source_line_type' => $definition['line_source'],
                    'source_line_id' => $line->getKey(),
                    'sort_order' => $line->getAttribute('sort_order') ?? $index,
                ]);
            })
            ->filter()
            ->values()
            ->all();
    }

    private function dateValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }

    private function remainingQuantity(Model $line, array $definition): float
    {
        if (! isset($definition['remaining'])) {
            return (float) $line->getAttribute('quantity');
        }

        [$quantityField, $usedField] = $definition['remaining'];

        return max(0, (float) $line->getAttribute($quantityField) - (float) $line->getAttribute($usedField));
    }

    private function unitPrice(Model $line, string $sourceType, array $definition): float
    {
        if (isset($definition['price'])) {
            return (float) $line->getAttribute($definition['price']);
        }
        if ($sourceType === 'delivery_order') {
            return (float) ($line->getRelationValue('salesOrderLine')?->unit_price ?? $line->getAttribute('unit_price') ?? 0);
        }
        if ($sourceType === 'goods_receipt') {
            return (float) ($line->getRelationValue('purchaseOrderLine')?->unit_price ?? $line->getAttribute('unit_price') ?? 0);
        }

        return (float) $line->getAttribute('unit_price');
    }

    private function sourceLinePayload(Model $line, string $sourceType): array
    {
        return match ($sourceType) {
            'sales_quotation' => ['quotation_line_id' => $line->getKey()],
            'sales_order' => ['sales_order_line_id' => $line->getKey()],
            'delivery_order' => [
                'delivery_order_line_id' => $line->getKey(),
                'sales_order_line_id' => $line->getAttribute('sales_order_line_id'),
            ],
            'proforma_invoice' => ['proforma_invoice_line_id' => $line->getKey()],
            'purchase_request' => ['purchase_request_line_id' => $line->getKey()],
            'purchase_order' => ['purchase_order_line_id' => $line->getKey()],
            'goods_receipt' => [
                'goods_receipt_line_id' => $line->getKey(),
                'purchase_order_line_id' => $line->getAttribute('purchase_order_line_id'),
            ],
            default => [],
        };
    }

    private function headerPayload(Model $document, string $sourceType): array
    {
        return match ($sourceType) {
            'sales_quotation' => [
                'quotation_id' => $document->getKey(),
                'sales_quotation_id' => $document->getKey(),
                'customer_id' => $document->getAttribute('customer_id'),
                'customer_address' => $document->getAttribute('customer_address'),
                'salesperson_id' => $document->getAttribute('salesperson_id'),
            ],
            'sales_order' => [
                'sales_order_id' => $document->getKey(),
                'customer_id' => $document->getAttribute('customer_id'),
                'customer_address' => $document->getAttribute('customer_address'),
                'shipping_address' => $document->getAttribute('shipping_address'),
                'salesperson_id' => $document->getAttribute('salesperson_id'),
            ],
            'proforma_invoice' => [
                'proforma_invoice_id' => $document->getKey(),
                'customer_id' => $document->getAttribute('customer_id'),
                'customer_address' => $document->getAttribute('customer_address'),
            ],
            'delivery_order' => [
                'delivery_order_id' => $document->getKey(),
                'sales_order_id' => $document->getAttribute('sales_order_id'),
                'customer_id' => $document->getAttribute('customer_id'),
                'customer_address' => $document->getAttribute('shipping_address'),
            ],
            'purchase_request' => [
                'purchase_request_id' => $document->getKey(),
                'purchase_request_number' => $document->getAttribute('request_number'),
            ],
            'purchase_order' => [
                'purchase_order_id' => $document->getKey(),
                'purchase_order_number' => $document->getAttribute('order_number'),
                'vendor_id' => $document->getAttribute('vendor_id'),
                'vendor_address' => $document->getAttribute('vendor_address'),
                'shipping_address' => $document->getAttribute('shipping_address'),
            ],
            'goods_receipt' => [
                'goods_receipt_id' => $document->getKey(),
                'purchase_order_id' => $document->getAttribute('purchase_order_id'),
                'vendor_id' => $document->getAttribute('vendor_id'),
            ],
            default => [],
        };
    }
}
