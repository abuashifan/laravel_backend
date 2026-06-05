<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovementLine;
use Illuminate\Database\Eloquent\Builder;

class StockBalanceService
{
    public function __construct(private readonly AverageCostService $avgCostService) {}

    public function getOrCreateBalance(int $productId, int $warehouseId): StockBalance
    {
        return StockBalance::query()->firstOrCreate([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
        ], [
            'quantity_on_hand' => 0,
            'quantity_reserved' => 0,
            'quantity_available' => 0,
            'average_cost' => 0,
            'total_value' => 0,
        ]);
    }

    public function getBalance(int $productId, int $warehouseId): ?StockBalance
    {
        return StockBalance::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();
    }

    public function applyMovementLine(StockMovementLine $line): StockBalance
    {
        $direction = (string) ($line->direction ?? $line->stockMovement?->direction ?? '');
        if (! in_array($direction, ['in', 'out'], true)) {
            throw ApiException::make('INVALID_STOCK_MOVEMENT_DIRECTION', 'Invalid stock movement direction.', 422);
        }

        $qty = (float) $line->quantity;
        if ($qty <= 0) {
            throw ApiException::make('INVALID_STOCK_MOVEMENT_QUANTITY', 'Invalid stock movement quantity.', 422);
        }

        $balance = $this->getOrCreateBalance((int) $line->product_id, (int) $line->warehouse_id);

        if ($direction === 'out') {
            $this->assertSufficientStock((int) $line->product_id, (int) $line->warehouse_id, $qty);
        }

        // Apply average cost valuation + update line valuation metadata.
        if ($direction === 'in') {
            // If incoming cost isn't provided (e.g. operational receipt), keep it 0 for now.
            // Integration services should supply unit_cost where available.
            $this->avgCostService->applyIncoming($balance, $line);
        } else {
            $this->avgCostService->applyOutgoing($balance, $line);
        }

        $movement = $line->relationLoaded('stockMovement') ? $line->stockMovement : null;
        $balance->last_movement_id = $movement?->id ?? $line->stock_movement_id;
        $balance->last_movement_at = $movement?->movement_date ?? $movement?->posted_at ?? now();

        // Persist both line and balance changes.
        $line->save();
        $balance->save();

        return $balance;
    }

    public function reverseMovementLine(StockMovementLine $line): StockBalance
    {
        $direction = (string) ($line->direction ?? $line->stockMovement?->direction ?? '');
        if ($direction === 'in') {
            $line->direction = 'out';
        } elseif ($direction === 'out') {
            $line->direction = 'in';
        }

        return $this->applyMovementLine($line);
    }

    public function assertSufficientStock(int $productId, int $warehouseId, float $qty): void
    {
        if ((bool) config('inventory.allow_negative_stock', false)) {
            return;
        }

        $balance = $this->getBalance($productId, $warehouseId);
        $available = (float) ($balance?->quantity_available ?? 0);
        if ($available + 1e-9 < $qty) {
            throw ApiException::make('INSUFFICIENT_STOCK', 'Insufficient stock available.', 422);
        }
    }

    public function list(array $filters = []): Builder
    {
        $query = StockBalance::query()->with(['product', 'warehouse']);

        if (! empty($filters['product_id'])) {
            $query->where('product_id', (int) $filters['product_id']);
        }
        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', (int) $filters['warehouse_id']);
        }
        if (! empty($filters['has_stock'])) {
            $query->where('quantity_on_hand', '!=', 0);
        }

        return $query->orderBy('product_id')->orderBy('warehouse_id');
    }

    public function getProductWarehouseBalance(int $productId, int $warehouseId): array
    {
        $balance = $this->getOrCreateBalance($productId, $warehouseId)->loadMissing(['product', 'warehouse']);

        return [
            'product_id' => (int) $balance->product_id,
            'product_code' => $balance->product?->product_code,
            'product_name' => $balance->product?->product_name,
            'warehouse_id' => (int) $balance->warehouse_id,
            'warehouse_code' => $balance->warehouse?->code,
            'warehouse_name' => $balance->warehouse?->name,
            'quantity_on_hand' => (float) $balance->quantity_on_hand,
            'quantity_reserved' => (float) $balance->quantity_reserved,
            'quantity_available' => (float) $balance->quantity_available,
            'average_cost' => (float) $balance->average_cost,
            'total_value' => (float) $balance->total_value,
            'last_movement_id' => $balance->last_movement_id,
            'last_movement_at' => optional($balance->last_movement_at)->toDateTimeString(),
        ];
    }
}
