<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\JournalEntryLine;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Support\AccountMapping\AccountMappingKey;
use Tests\TenantTestCase;

class StockMovementVoidTest extends TenantTestCase
{
    protected Unit $unit;

    protected Warehouse $warehouse;

    protected Product $product;

    protected int $inventoryAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryAccountId = $this->seedInventoryMappings();
        $this->unit = Unit::query()->create([
            'code' => 'PCS',
            'name' => 'Pieces',
            'precision' => 0,
            'is_active' => true,
        ]);
        $this->warehouse = Warehouse::factory()->create([
            'code' => 'WH1',
            'name' => 'Main',
            'is_default' => true,
        ]);
        $this->product = Product::factory()->stockItem()->create([
            'product_code' => 'SKU-H5',
            'product_name' => 'H5 Stock Item',
            'unit_id' => $this->unit->id,
        ]);
    }

    public function test_creates_a_posted_journal_entry_when_stock_movement_is_posted(): void
    {
        $movement = $this->createDraftAdjustmentInMovement();

        $this->patchJson('/api/inventory/stock-movements/'.$movement->id.'/post', [], $this->headers)
            ->assertSuccessful();

        $movement->refresh();
        $journal = JournalEntry::query()
            ->where('source_type', 'stock_movement')
            ->where('source_id', $movement->id)
            ->firstOrFail();

        $this->assertSame((int) $journal->id, (int) $movement->journal_entry_id);
        $this->assertSame('posted', (string) $journal->status);
        $this->assertTrue((bool) $journal->is_system_generated);
        $this->assertJournalBalances($journal);
        $this->assertBalanceQuantity(10.0);
    }

    public function test_voids_the_original_journal_entry_when_stock_movement_is_voided(): void
    {
        $movement = $this->postAdjustmentInMovement();
        $originalJournalId = (int) $movement->journal_entry_id;

        $this->patchJson('/api/inventory/stock-movements/'.$movement->id.'/void', [
            'reason' => 'Test void H5',
        ], $this->headers)->assertSuccessful();

        $originalJournal = JournalEntry::query()->findOrFail($originalJournalId);
        $this->assertSame('void', (string) $originalJournal->status);

        $this->assertDatabaseHas('stock_movements', [
            'source_type' => 'reversal',
            'source_id' => $movement->id,
            'reversal_of_id' => $movement->id,
        ], 'tenant');
    }

    public function test_nets_off_gl_correctly_after_voiding_no_double_posted_journal(): void
    {
        $debitBeforePost = $this->postedDebitForInventoryAccount();
        $movement = $this->postAdjustmentInMovement();
        $debitAfterPost = $this->postedDebitForInventoryAccount();

        $this->assertSame($debitBeforePost + 1000.0, $debitAfterPost);

        $this->patchJson('/api/inventory/stock-movements/'.$movement->id.'/void', [
            'reason' => 'Test void H5',
        ], $this->headers)->assertSuccessful();

        $debitAfterVoid = $this->postedDebitForInventoryAccount();
        $this->assertSame($debitBeforePost, $debitAfterVoid);

        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'stock_movement')
            ->where('source_id', $movement->id)
            ->where('status', 'posted')
            ->count());
    }

    public function test_voids_a_draft_movement_without_creating_reversal_journal(): void
    {
        $movement = $this->createDraftAdjustmentInMovement();

        $this->patchJson('/api/inventory/stock-movements/'.$movement->id.'/void', [
            'reason' => 'Void draft H5',
        ], $this->headers)->assertSuccessful();

        $movement->refresh();
        $this->assertSame('void', (string) $movement->status);
        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'stock_movement')
            ->where('source_id', $movement->id)
            ->count());
        $this->assertSame(0, StockMovement::query()
            ->where('reversal_of_id', $movement->id)
            ->count());
    }

    public function test_blocks_voiding_an_already_voided_movement(): void
    {
        $movement = $this->postAdjustmentInMovement();

        $this->patchJson('/api/inventory/stock-movements/'.$movement->id.'/void', [
            'reason' => 'First void H5',
        ], $this->headers)->assertSuccessful();

        $this->patchJson('/api/inventory/stock-movements/'.$movement->id.'/void', [
            'reason' => 'Second void H5',
        ], $this->headers)->assertStatus(422);
    }

    private function createDraftAdjustmentInMovement(): StockMovement
    {
        $response = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-10',
            'movement_type' => 'adjustment_in',
            'description' => 'H5 adjustment in',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'warehouse_id' => $this->warehouse->id,
                    'unit_id' => $this->unit->id,
                    'quantity' => 10,
                    'unit_cost' => 100,
                ],
            ],
        ], $this->headers)->assertCreated();

        return StockMovement::query()->findOrFail((int) $response->json('data.id'));
    }

    private function postAdjustmentInMovement(): StockMovement
    {
        $movement = $this->createDraftAdjustmentInMovement();

        $this->patchJson('/api/inventory/stock-movements/'.$movement->id.'/post', [], $this->headers)
            ->assertSuccessful();

        return $movement->refresh();
    }

    private function postedDebitForInventoryAccount(): float
    {
        return (float) JournalEntryLine::query()
            ->where('account_id', $this->inventoryAccountId)
            ->whereHas('journalEntry', fn ($query) => $query
                ->where('status', 'posted')
                ->where('is_obsolete', false))
            ->sum('debit');
    }

    private function assertBalanceQuantity(float $expected): void
    {
        $balance = StockBalance::query()
            ->where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->firstOrFail();

        $this->assertSame($expected, (float) $balance->quantity_on_hand);
    }

    private function assertJournalBalances(JournalEntry $journal): void
    {
        $journal->loadMissing('lines');

        $this->assertSame(
            round((float) $journal->lines->sum('debit'), 2),
            round((float) $journal->lines->sum('credit'), 2),
        );
    }

    private function seedInventoryMappings(): int
    {
        $inventory = ChartOfAccount::factory()->asset()->create([
            'account_code' => '1400',
            'account_name' => 'Inventory',
        ]);
        $cogs = ChartOfAccount::factory()->expense()->create([
            'account_code' => '5100',
            'account_name' => 'COGS',
        ]);
        $equity = ChartOfAccount::factory()->equity()->create([
            'account_code' => '3000',
            'account_name' => 'Equity',
        ]);
        $gain = ChartOfAccount::factory()->revenue()->create([
            'account_code' => '4100',
            'account_name' => 'Adjustment Gain',
        ]);
        $loss = ChartOfAccount::factory()->expense()->create([
            'account_code' => '5200',
            'account_name' => 'Adjustment Loss',
        ]);

        AccountMapping::factory()->create(['mapping_key' => AccountMappingKey::INVENTORY_ASSET, 'module' => 'inventory', 'account_id' => $inventory->id]);
        AccountMapping::factory()->create(['mapping_key' => AccountMappingKey::INVENTORY_COGS, 'module' => 'inventory', 'account_id' => $cogs->id]);
        AccountMapping::factory()->create(['mapping_key' => AccountMappingKey::OPENING_BALANCE_EQUITY, 'module' => 'opening_balance', 'account_id' => $equity->id]);
        AccountMapping::factory()->create(['mapping_key' => AccountMappingKey::INVENTORY_ADJUSTMENT_GAIN, 'module' => 'inventory', 'account_id' => $gain->id]);
        AccountMapping::factory()->create(['mapping_key' => AccountMappingKey::INVENTORY_ADJUSTMENT_LOSS, 'module' => 'inventory', 'account_id' => $loss->id]);

        return (int) $inventory->id;
    }
}
