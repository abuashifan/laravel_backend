<?php

namespace Tests\Unit;

use App\Services\Transactions\Checkers\InventoryTransactionDependencyChecker;
use App\Support\Transaction\TransactionAction;
use App\Support\Transaction\TransactionModule;
use Tests\TestCase;

class InventoryTransactionDependencyCheckerTest extends TestCase
{
    public function test_inventory_edit_is_blocked_when_stock_movements_have_been_generated(): void
    {
        $checker = new InventoryTransactionDependencyChecker();

        $transaction = [
            'status' => 'draft',
            'stock_movement_id' => 27,
            'metadata' => ['stock_movement_ids' => [27, 28]],
        ];

        $this->assertTrue($checker->hasBlockingDependencies($transaction, TransactionAction::EDIT, TransactionModule::INVENTORY));
        $this->assertSame(
            'INVENTORY_GENERATED_STOCK_MOVEMENT_LOCKED',
            $checker->blockingReasons($transaction, TransactionAction::EDIT, TransactionModule::INVENTORY)[0]['code']
        );
    }

    public function test_inventory_edit_remains_clear_for_plain_draft_documents(): void
    {
        $checker = new InventoryTransactionDependencyChecker();

        $this->assertFalse($checker->hasBlockingDependencies(
            ['status' => 'draft'],
            TransactionAction::EDIT,
            TransactionModule::INVENTORY,
        ));
        $this->assertSame([], $checker->blockingReasons(['status' => 'draft'], TransactionAction::EDIT, TransactionModule::INVENTORY));
    }
}