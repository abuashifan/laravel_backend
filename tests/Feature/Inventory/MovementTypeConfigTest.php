<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Services\Inventory\StockMovementValidationService;
use Tests\Feature\Journal\JournalTestCase;

/**
 * M1 — Config transfer type alignment.
 *
 * Verifies that transfer_in/transfer_out have been removed from config/inventory.php
 * and that config movement_types and ALLOWED_MOVEMENT_TYPES are aligned.
 */
class MovementTypeConfigTest extends JournalTestCase
{
    // -------------------------------------------------------------------------
    // Test 1 — transfer_in/transfer_out are no longer in config
    // -------------------------------------------------------------------------

    public function test_transfer_types_are_not_in_config(): void
    {
        $configTypes = config('inventory.movement_types', []);

        $this->assertNotContains('transfer_in', $configTypes, 'transfer_in must be removed from config — not implemented');
        $this->assertNotContains('transfer_out', $configTypes, 'transfer_out must be removed from config — not implemented');
    }

    // -------------------------------------------------------------------------
    // Test 2 — Config types match allowed types (no orphaned entries)
    // -------------------------------------------------------------------------

    public function test_config_movement_types_are_subset_of_allowed_types(): void
    {
        $configTypes  = config('inventory.movement_types', []);
        $service      = app(StockMovementValidationService::class);
        $allowedTypes = $this->getAllowedMovementTypes($service);

        foreach ($configTypes as $type) {
            $this->assertContains(
                $type,
                $allowedTypes,
                "Config type '{$type}' is in inventory.movement_types but not in ALLOWED_MOVEMENT_TYPES — either add it to the validator or remove it from config"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Test 3 — transfer_in via API is rejected with 422 INVALID_MOVEMENT_TYPE
    // -------------------------------------------------------------------------

    public function test_transfer_in_movement_type_is_rejected_by_api(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh   = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $prod = Product::query()->create([
            'product_code'  => 'TRF-001',
            'product_name'  => 'Transfer Item',
            'product_type'  => 'goods',
            'unit_id'       => $unit->id,
            'is_stock_item' => true,
            'is_active'     => true,
        ]);

        $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-01',
            'movement_type' => 'transfer_in',
            'lines' => [[
                'product_id'   => $prod->id,
                'warehouse_id' => $wh->id,
                'unit_id'      => $unit->id,
                'quantity'     => 5,
                'unit_cost'    => 100,
            ]],
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_MOVEMENT_TYPE');
    }

    // -------------------------------------------------------------------------
    // Test 4 — transfer_out via API is rejected with 422 INVALID_MOVEMENT_TYPE
    // -------------------------------------------------------------------------

    public function test_transfer_out_movement_type_is_rejected_by_api(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh   = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $prod = Product::query()->create([
            'product_code'  => 'TRF-002',
            'product_name'  => 'Transfer Item Out',
            'product_type'  => 'goods',
            'unit_id'       => $unit->id,
            'is_stock_item' => true,
            'is_active'     => true,
        ]);

        $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-01',
            'movement_type' => 'transfer_out',
            'lines' => [[
                'product_id'   => $prod->id,
                'warehouse_id' => $wh->id,
                'unit_id'      => $unit->id,
                'quantity'     => 3,
            ]],
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_MOVEMENT_TYPE');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Access private ALLOWED_MOVEMENT_TYPES via reflection.
     *
     * @return array<string>
     */
    private function getAllowedMovementTypes(StockMovementValidationService $service): array
    {
        $ref = new \ReflectionClass($service);
        $const = $ref->getReflectionConstant('ALLOWED_MOVEMENT_TYPES');
        return (array) $const->getValue();
    }
}
