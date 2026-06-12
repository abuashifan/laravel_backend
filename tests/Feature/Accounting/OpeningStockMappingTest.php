<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Support\AccountMapping\AccountMappingKey;
use Tests\Feature\Journal\JournalTestCase;

/**
 * M9 — Canonical opening stock key.
 *
 * Verifies that opening_balance.equity is the single canonical key
 * for opening stock journals, and that inventory.opening_stock_equity
 * is not in config or used anywhere in active service code.
 */
class OpeningStockMappingTest extends JournalTestCase
{
    // -------------------------------------------------------------------------
    // Test 1 — inventory.opening_stock_equity is not a known config key
    // -------------------------------------------------------------------------

    public function test_inventory_opening_stock_equity_is_not_in_config(): void
    {
        $mappings = config('account_mappings.required_mappings', []);

        $this->assertArrayNotHasKey(
            'inventory.opening_stock_equity',
            $mappings,
            'inventory.opening_stock_equity should be removed from config — canonical key is opening_balance.equity'
        );

        $this->assertArrayHasKey(
            'opening_balance.equity',
            $mappings,
            'opening_balance.equity must remain as the canonical opening stock key'
        );
    }

    // -------------------------------------------------------------------------
    // Test 2 — opening_stock movement posts with canonical mapping
    // -------------------------------------------------------------------------

    public function test_opening_stock_movement_posts_journal_with_canonical_mapping(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        [$inventory, $equity, $cogs] = $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh   = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $prod = Product::query()->create([
            'product_code' => 'OS-001',
            'product_name' => 'Opening Stock Item',
            'product_type' => 'goods',
            'unit_id'      => $unit->id,
            'is_stock_item' => true,
            'is_active'    => true,
        ]);

        $movement = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-01',
            'movement_type' => 'opening_stock',
            'lines' => [[
                'product_id'  => $prod->id,
                'warehouse_id' => $wh->id,
                'unit_id'     => $unit->id,
                'quantity'    => 10,
                'unit_cost'   => 500,
            ]],
        ], $ctx['headers'])->assertCreated()->json('data.id');

        $posted = $this->patchJson('/api/inventory/stock-movements/'.$movement.'/post', [], $ctx['headers'])
            ->assertOk()
            ->json('data');

        $this->assertSame('posted', $posted['status']);

        // Verify journal exists and uses opening_balance.equity account
        $journal = \App\Models\Tenant\JournalEntry::query()
            ->where('source_type', 'stock_movement')
            ->where('source_id', $movement)
            ->firstOrFail();

        $equityLine = $journal->lines()->where('account_id', $equity)->first();
        $this->assertNotNull($equityLine, 'Journal must have a line on the opening_balance.equity account');
        $this->assertEqualsWithDelta(5000.0, (float) $equityLine->credit, 0.01);
    }

    // -------------------------------------------------------------------------
    // Test 3 — Missing canonical mapping → clear error, not silent failure
    // -------------------------------------------------------------------------

    public function test_opening_stock_movement_fails_when_canonical_mapping_missing(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $this->seedInventoryMappings();

        // Remove the canonical key
        AccountMapping::query()->where('mapping_key', AccountMappingKey::OPENING_BALANCE_EQUITY)
            ->update(['account_id' => null]);

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh   = Warehouse::query()->create(['code' => 'WH2', 'name' => 'WH2', 'is_default' => true, 'is_active' => true]);
        $prod = Product::query()->create([
            'product_code' => 'OS-002',
            'product_name' => 'Item B',
            'product_type' => 'goods',
            'unit_id'      => $unit->id,
            'is_stock_item' => true,
            'is_active'    => true,
        ]);

        $movement = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-01',
            'movement_type' => 'opening_stock',
            'lines' => [[
                'product_id'   => $prod->id,
                'warehouse_id' => $wh->id,
                'unit_id'      => $unit->id,
                'quantity'     => 5,
                'unit_cost'    => 100,
            ]],
        ], $ctx['headers'])->assertCreated()->json('data.id');

        $this->patchJson('/api/inventory/stock-movements/'.$movement.'/post', [], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ACCOUNT_MAPPING_MISSING');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Seed inventory + opening_balance.equity mappings.
     *
     * @return array{0:int,1:int,2:int}  [inventory_account_id, equity_account_id, cogs_account_id]
     */
    private function seedInventoryMappings(): array
    {
        $inventory = ChartOfAccount::query()->create([
            'account_code' => '1300', 'account_name' => 'Inventory',
            'account_type' => 'asset', 'normal_balance' => 'debit',
            'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false,
        ]);
        $equity = ChartOfAccount::query()->create([
            'account_code' => '3100', 'account_name' => 'Opening Balance Equity',
            'account_type' => 'equity', 'normal_balance' => 'credit',
            'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false,
        ]);
        $cogs = ChartOfAccount::query()->create([
            'account_code' => '5000', 'account_name' => 'COGS',
            'account_type' => 'expense', 'normal_balance' => 'debit',
            'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false,
        ]);

        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_ASSET, 'module' => 'inventory', 'account_id' => $inventory->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_COGS, 'module' => 'inventory', 'account_id' => $cogs->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::OPENING_BALANCE_EQUITY, 'module' => 'opening_balance', 'account_id' => $equity->id, 'is_active' => true]);

        return [(int) $inventory->id, (int) $equity->id, (int) $cogs->id];
    }
}
