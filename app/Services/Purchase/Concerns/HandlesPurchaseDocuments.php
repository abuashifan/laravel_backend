<?php

namespace App\Services\Purchase\Concerns;

use App\Exceptions\ApiException;
use App\Models\Tenant\Contact;
use App\Models\Tenant\Product;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\Model;

trait HandlesPurchaseDocuments
{
    private function ensureVendorExists(int $vendorId): void
    {
        if (! Contact::query()->whereKey($vendorId)->where('is_supplier', true)->exists()) {
            throw ApiException::make('VENDOR_NOT_FOUND', 'Vendor not found.', 422);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    private function normalizeRequestLines(array $lines): array
    {
        return array_values(array_map(function (array $line, int $index): array {
            $product = null;
            if (! empty($line['product_id'])) {
                $product = Product::query()->find((int) $line['product_id']);
            }

            $quantity = (float) ($line['quantity'] ?? 0);
            $estimatedUnitPrice = (float) ($line['estimated_unit_price'] ?? 0);

            return [
                'product_id' => $line['product_id'] ?? null,
                'product_code' => $line['product_code'] ?? $product?->product_code,
                'description' => $line['description'] ?? $product?->product_name,
                'quantity' => $quantity,
                'unit_id' => $line['unit_id'] ?? $product?->unit_id,
                'estimated_unit_price' => $estimatedUnitPrice,
                'estimated_line_total' => round($quantity * $estimatedUnitPrice, 2, PHP_ROUND_HALF_UP),
                'warehouse_id' => $line['warehouse_id'] ?? null,
                'department_id' => $line['department_id'] ?? null,
                'project_id' => $line['project_id'] ?? null,
                'source_line_type' => $line['source_line_type'] ?? null,
                'source_line_id' => $line['source_line_id'] ?? null,
                'sort_order' => $line['sort_order'] ?? $index,
                'metadata' => $line['metadata'] ?? null,
            ];
        }, $lines, array_keys($lines)));
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    private function normalizePurchaseLines(array $lines, ?callable $sourceMap = null): array
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
                'unit_price' => (float) ($line['unit_price'] ?? $line['estimated_unit_price'] ?? 0),
                'discount_type' => $line['discount_type'] ?? null,
                'discount_value' => $line['discount_value'] ?? null,
                'tax_id' => $line['tax_id'] ?? null,
                'tax_rate' => $line['tax_rate'] ?? null,
                'warehouse_id' => $line['warehouse_id'] ?? null,
                'department_id' => $line['department_id'] ?? null,
                'project_id' => $line['project_id'] ?? null,
                'expense_account_id' => $line['expense_account_id'] ?? null,
                'source_line_type' => $line['source_line_type'] ?? null,
                'source_line_id' => $line['source_line_id'] ?? null,
                'sort_order' => $line['sort_order'] ?? $index,
                'metadata' => $line['metadata'] ?? null,
            ], $sourceMap ? $sourceMap($line, $index) : []);
        }, $lines, array_keys($lines)));
    }

    private function guardedPurchaseHeader(array $data, array $excluded = []): array
    {
        $excluded = array_merge($excluded, ['lines', 'vendor_deposit']);

        return array_filter(
            $data,
            fn (string $key): bool => ! in_array($key, $excluded, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function auditPurchase(?AuditLogService $auditLogService, string $event, Model $record, string $numberField, array $meta = []): void
    {
        if (! $auditLogService) {
            return;
        }

        $auditLogService->logSuccess([
            'event' => $event,
            'module' => 'purchase',
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
