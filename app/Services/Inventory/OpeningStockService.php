<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\Tenant\StockMovement;
use App\Services\Validation\BusinessReferenceValidator;
use Illuminate\Support\Facades\DB;

class OpeningStockService
{
    public function __construct(
        private readonly StockMovementService $stockMovementService,
        private readonly BusinessReferenceValidator $referenceValidator,
    ) {
    }

    public function post(array $data): StockMovement
    {
        return DB::connection('tenant')->transaction(function () use ($data): StockMovement {
            $product = $this->referenceValidator->product((int) ($data['product_id'] ?? 0), true);
            $warehouse = $this->referenceValidator->warehouse((int) ($data['warehouse_id'] ?? 0));

            $this->assertOpeningStockDoesNotExist((int) $product->id, (int) $warehouse->id);

            return $this->stockMovementService->createAndPost([
                'movement_date' => (string) ($data['date'] ?? ''),
                'movement_type' => 'opening_stock',
                'description' => (string) ($data['description'] ?? 'Opening stock'),
                'lines' => [[
                    'product_id' => (int) $product->id,
                    'warehouse_id' => (int) $warehouse->id,
                    'unit_id' => (int) $product->unit_id,
                    'quantity' => $data['quantity'] ?? 0,
                    'unit_cost' => $data['unit_cost'] ?? 0,
                ]],
            ]);
        });
    }

    private function assertOpeningStockDoesNotExist(int $productId, int $warehouseId): void
    {
        $exists = StockMovement::query()
            ->where('movement_type', 'opening_stock')
            ->where('status', 'posted')
            ->whereHas('lines', function ($query) use ($productId, $warehouseId): void {
                $query->where('product_id', $productId)
                    ->where('warehouse_id', $warehouseId);
            })
            ->exists();

        if ($exists) {
            throw ApiException::make(
                'OPENING_STOCK_ALREADY_EXISTS',
                'Opening stock already exists for this product and warehouse.',
                422,
                [
                    'product_id' => ['Opening stock already exists for this product and warehouse.'],
                    'warehouse_id' => ['Opening stock already exists for this product and warehouse.'],
                ],
            );
        }
    }
}
