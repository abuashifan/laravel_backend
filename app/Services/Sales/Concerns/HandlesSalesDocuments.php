<?php

namespace App\Services\Sales\Concerns;

use App\Exceptions\ApiException;
use App\Models\Tenant\Contact;
use App\Models\Tenant\Product;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\Model;

trait HandlesSalesDocuments
{
    private function ensureCustomerExists(int $customerId): void
    {
        if (! Contact::query()->whereKey($customerId)->where('is_customer', true)->exists()) {
            throw ApiException::make('CUSTOMER_NOT_FOUND', 'Customer not found.', 422);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    private function normalizeLines(array $lines, ?callable $sourceMap = null): array
    {
        return array_values(array_map(function (array $line, int $index) use ($sourceMap): array {
            $product = null;
            if (! empty($line['product_id'])) {
                $product = Product::query()->find((int) $line['product_id']);
            }

            return array_merge([
                'product_id' => $line['product_id'] ?? null,
                'product_code' => $line['product_code'] ?? $product?->product_code,
                'description' => $line['description'] ?? $product?->product_name,
                'quantity' => (float) ($line['quantity'] ?? 0),
                'unit_id' => $line['unit_id'] ?? $product?->unit_id,
                'unit_price' => (float) ($line['unit_price'] ?? 0),
                'discount_type' => $line['discount_type'] ?? null,
                'discount_value' => $line['discount_value'] ?? null,
                'tax_id' => $line['tax_id'] ?? null,
                'tax_rate' => $line['tax_rate'] ?? null,
                'warehouse_id' => $line['warehouse_id'] ?? null,
                'department_id' => $line['department_id'] ?? null,
                'project_id' => $line['project_id'] ?? null,
                'source_line_type' => $line['source_line_type'] ?? null,
                'source_line_id' => $line['source_line_id'] ?? null,
                'sort_order' => $line['sort_order'] ?? $index,
                'metadata' => $line['metadata'] ?? null,
            ], $sourceMap ? $sourceMap($line, $index) : []);
        }, $lines, array_keys($lines)));
    }

    private function guardedForHeader(array $data, array $excluded = []): array
    {
        $excluded = array_merge($excluded, ['lines', 'down_payment']);

        return array_filter(
            $data,
            fn (string $key): bool => ! in_array($key, $excluded, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function auditSales(?AuditLogService $auditLogService, string $event, string $module, Model $record, string $numberField, array $meta = []): void
    {
        if (! $auditLogService) {
            return;
        }

        $auditLogService->logSuccess([
            'event' => $event,
            'module' => $module,
            'record_type' => $record->getTable(),
            'record_id' => (string) $record->getKey(),
            'record_number' => (string) $record->getAttribute($numberField),
            'user_id' => auth()->id(),
            'source_type' => $record->getAttribute('source_type'),
            'source_id' => $record->getAttribute('source_id'),
            'source_number' => $record->getAttribute('source_number'),
            'source_revision' => $record->getAttribute('source_revision'),
            'metadata' => $meta,
        ], tenant: true);
    }
}
