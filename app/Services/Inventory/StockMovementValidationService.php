<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Warehouse;
use App\Services\Transactions\TransactionDateGuardService;
use App\Support\Inventory\InventoryMovementType;
use Illuminate\Support\Facades\Schema;

class StockMovementValidationService
{
    public function __construct(
        private readonly InventoryQuantityService $qtyService,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly InventorySourceService $sourceService,
    ) {
    }

    public function validateMovementType(string $type): void
    {
        if (! InventoryMovementType::exists($type)) {
            throw ApiException::make('INVALID_MOVEMENT_TYPE', 'Invalid movement type: '.$type, 422);
        }
    }

    public function validateDirection(string $direction): void
    {
        if (! in_array($direction, ['in', 'out'], true)) {
            throw ApiException::make('INVALID_DIRECTION', 'Invalid direction: '.$direction, 422);
        }
    }

    public function directionForType(string $type): string
    {
        $in = ['purchase_in', 'sales_return_in', 'adjustment_in', 'opname_in', 'transfer_in', 'opening_stock'];
        $out = ['sales_out', 'purchase_return_out', 'adjustment_out', 'opname_out', 'transfer_out'];
        if (in_array($type, $in, true)) return 'in';
        if (in_array($type, $out, true)) return 'out';
        return 'in';
    }

    public function validateLines(array $lines): void
    {
        if ($lines === []) {
            throw ApiException::make('LINES_REQUIRED', 'Stock movement lines are required.', 422);
        }

        foreach ($lines as $idx => $ln) {
            $this->qtyService->assertPositiveQuantity($ln['quantity'] ?? 0);
        }
    }

    public function validateProductIsStockable(Product $product): void
    {
        // Project uses `is_stock_item` to indicate stockable items.
        if (Schema::connection('tenant')->hasColumn('products', 'is_stock_item')) {
            if (! $product->isStockItem()) {
                throw ApiException::make('PRODUCT_NOT_STOCKABLE', 'Product is not stockable.', 422);
            }
        }
    }

    public function validateWarehouseExists(int $warehouseId): void
    {
        if (! Warehouse::query()->whereKey($warehouseId)->exists()) {
            throw ApiException::make('WAREHOUSE_NOT_FOUND', 'Warehouse not found.', 422);
        }
    }

    public function validatePeriodNotLocked(string $movementDate): void
    {
        $check = $this->dateGuardService->check($movementDate, 'post', 'inventory');
        if ($check->denied()) {
            $arr = $check->toArray();
            throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']);
        }
    }

    public function validateCannotEditPosted(StockMovement $movement): void
    {
        if ($movement->status === 'posted') {
            throw ApiException::make('STOCK_MOVEMENT_POSTED_READ_ONLY', 'Posted stock movement cannot be edited.', 422);
        }
    }

    public function validateNoDuplicateSource(array $data): void
    {
        $sourceType = $data['source_type'] ?? null;
        $sourceId = $data['source_id'] ?? null;
        if (! $sourceType || ! $sourceId) {
            return;
        }

        $this->sourceService->assertNoDuplicateSourceMovement((string) $sourceType, (int) $sourceId);
    }
}

