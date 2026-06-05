<?php

namespace App\Services\Inventory;

use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovementLine;
use Illuminate\Support\Facades\DB;

class StockBalanceRebuildService
{
    public function __construct(private readonly StockBalanceService $balances) {}

    public function rebuildAll(): void
    {
        DB::connection('tenant')->transaction(function () {
            StockBalance::query()->delete();

            $this->replayLines(StockMovementLine::query());
        });
    }

    public function rebuildProduct(int $productId): void
    {
        DB::connection('tenant')->transaction(function () use ($productId) {
            StockBalance::query()->where('product_id', $productId)->delete();

            $this->replayLines(StockMovementLine::query()->where('product_id', $productId));
        });
    }

    public function rebuildWarehouse(int $warehouseId): void
    {
        DB::connection('tenant')->transaction(function () use ($warehouseId) {
            StockBalance::query()->where('warehouse_id', $warehouseId)->delete();

            $this->replayLines(StockMovementLine::query()->where('warehouse_id', $warehouseId));
        });
    }

    public function rebuildProductWarehouse(int $productId, int $warehouseId): void
    {
        DB::connection('tenant')->transaction(function () use ($productId, $warehouseId) {
            StockBalance::query()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->delete();

            $this->replayLines(StockMovementLine::query()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId));
        });
    }

    private function replayLines($lineQuery): void
    {
        $base = (clone $lineQuery)
            ->select('stock_movement_lines.*')
            ->join('stock_movements', 'stock_movements.id', '=', 'stock_movement_lines.stock_movement_id')
            ->where('stock_movements.status', 'posted')
            ->orderBy('stock_movements.movement_date')
            ->orderBy('stock_movements.movement_number')
            ->orderBy('stock_movement_lines.sort_order')
            ->orderBy('stock_movement_lines.id')
            ->with('stockMovement');

        $base->chunk(500, function ($lines) {
            foreach ($lines as $ln) {
                $this->balances->applyMovementLine($ln);
            }
        });
    }
}
