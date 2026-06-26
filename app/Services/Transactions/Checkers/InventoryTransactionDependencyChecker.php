<?php

namespace App\Services\Transactions\Checkers;

use App\Support\Transaction\TransactionAction;
use App\Support\Transaction\TransactionModule;

class InventoryTransactionDependencyChecker extends BaseTransactionDependencyChecker
{
    public function hasBlockingDependencies(mixed $transaction, string $action, string $module): bool
    {
        if ($module !== TransactionModule::INVENTORY || $action !== TransactionAction::EDIT) {
            return false;
        }

        return $this->hasGeneratedStockMovementDependency($transaction) || $this->isFinalizedInventoryDocument($transaction);
    }

    public function blockingReasons(mixed $transaction, string $action, string $module): array
    {
        if (! $this->hasBlockingDependencies($transaction, $action, $module)) {
            return [];
        }

        $reasons = [];

        if ($this->hasGeneratedStockMovementDependency($transaction)) {
            $reasons[] = [
                'code' => 'INVENTORY_GENERATED_STOCK_MOVEMENT_LOCKED',
                'message' => 'Inventory transaction already generated stock movements and cannot be edited.',
            ];
        }

        if ($this->isFinalizedInventoryDocument($transaction)) {
            $reasons[] = [
                'code' => 'INVENTORY_FINALIZED_DOCUMENT_LOCKED',
                'message' => 'Finalized inventory document is read-only.',
            ];
        }

        return $reasons;
    }

    private function hasGeneratedStockMovementDependency(mixed $transaction): bool
    {
        $stockMovementId = data_get($transaction, 'stock_movement_id');
        $stockMovementIds = data_get($transaction, 'metadata.stock_movement_ids');
        $stockMovementRelation = data_get($transaction, 'stockMovement');

        return ($stockMovementId !== null && $stockMovementId !== '')
            || (is_array($stockMovementIds) && $stockMovementIds !== [])
            || $stockMovementRelation !== null;
    }

    private function isFinalizedInventoryDocument(mixed $transaction): bool
    {
        $status = (string) data_get($transaction, 'status', '');

        return in_array($status, ['posted', 'counted', 'finalized', 'void'], true);
    }
}

