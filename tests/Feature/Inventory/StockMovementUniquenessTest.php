<?php

namespace Tests\Feature\Inventory;

use App\Models\Tenant\StockMovement;
use Illuminate\Database\QueryException;
use Tests\TenantTestCase;

class StockMovementUniquenessTest extends TenantTestCase
{
    public function test_same_source_type_id_and_movement_type_is_rejected_by_database(): void
    {
        $this->createMovement([
            'movement_number' => 'SM-001',
            'source_type' => 'stock_adjustment',
            'source_id' => 10,
            'movement_type' => 'adjustment_in',
        ]);

        $this->expectException(QueryException::class);

        $this->createMovement([
            'movement_number' => 'SM-002',
            'source_type' => 'stock_adjustment',
            'source_id' => 10,
            'movement_type' => 'adjustment_in',
        ]);
    }

    public function test_same_source_type_and_id_with_different_movement_type_is_allowed(): void
    {
        $this->createMovement([
            'movement_number' => 'SM-003',
            'source_type' => 'stock_adjustment',
            'source_id' => 11,
            'movement_type' => 'adjustment_in',
        ]);

        $this->createMovement([
            'movement_number' => 'SM-004',
            'source_type' => 'stock_adjustment',
            'source_id' => 11,
            'movement_type' => 'adjustment_out',
        ]);

        $this->assertSame(2, StockMovement::query()->where('source_type', 'stock_adjustment')->where('source_id', 11)->count());
    }

    public function test_reversal_source_does_not_conflict_with_original_source(): void
    {
        $original = $this->createMovement([
            'movement_number' => 'SM-005',
            'source_type' => 'stock_adjustment',
            'source_id' => 12,
            'movement_type' => 'adjustment_in',
        ]);

        $this->createMovement([
            'movement_number' => 'SM-006',
            'source_type' => 'reversal',
            'source_id' => $original->id,
            'movement_type' => 'adjustment_out',
            'reversal_of_id' => $original->id,
        ]);

        $this->assertSame(2, StockMovement::query()->count());
    }

    private function createMovement(array $overrides): StockMovement
    {
        return StockMovement::query()->create(array_merge([
            'movement_date' => '2026-05-20',
            'movement_type' => 'adjustment_in',
            'direction' => 'in',
            'status' => 'posted',
            'source_type' => 'stock_adjustment',
            'source_id' => 1,
            'total_quantity' => 1,
            'total_value' => 100,
            'posted_at' => now(),
        ], $overrides));
    }
}
