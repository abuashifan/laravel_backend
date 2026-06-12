<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement;
use App\Services\Transactions\TransactionDateGuardService;
use App\Services\Validation\BusinessReferenceValidator;
use App\Support\Inventory\InventoryMovementType;

class StockMovementValidationService
{
    private const ALLOWED_MOVEMENT_TYPES = [
        'purchase_in',
        'purchase_return_out',
        'sales_out',
        'sales_return_in',
        'adjustment_in',
        'adjustment_out',
        'opening_stock',
        'opname_in',
        'opname_out',
    ];

    public function __construct(
        private readonly InventoryQuantityService $qtyService,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly InventorySourceService $sourceService,
        private readonly BusinessReferenceValidator $referenceValidator,
    ) {
    }

    public function validateMovementType(string $type): void
    {
        if (! InventoryMovementType::exists($type) || ! in_array($type, self::ALLOWED_MOVEMENT_TYPES, true)) {
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
        $in = ['purchase_in', 'sales_return_in', 'adjustment_in', 'opname_in', 'opening_stock'];
        $out = ['sales_out', 'purchase_return_out', 'adjustment_out', 'opname_out'];
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
            if (isset($ln['unit_cost']) && (float) $ln['unit_cost'] < 0) {
                throw ApiException::make('UNIT_COST_INVALID', 'Unit cost cannot be negative.', 422);
            }
        }
    }

    public function validateProductIsStockable(Product $product): void
    {
        if (! $product->isActive()) {
            throw ApiException::make('PRODUCT_NOT_VALID', 'Product is inactive or not found.', 422);
        }
        if (! $product->isStockItem()) {
            throw ApiException::make('PRODUCT_NOT_STOCKABLE', 'Product is not stockable, stock movement cannot be posted.', 422);
        }
    }

    public function validateWarehouseExists(int $warehouseId): void
    {
        $this->referenceValidator->warehouse($warehouseId);
    }

    public function validateStockMovementLine(array $line): Product
    {
        return $this->referenceValidator->stockMovementLine($line);
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
