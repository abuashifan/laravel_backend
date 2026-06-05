<?php

namespace App\Services\Transactions\Checkers;

class InventoryTransactionDependencyChecker extends BaseTransactionDependencyChecker
{
    public function hasBlockingDependencies(mixed $transaction, string $action, string $module): bool
    {
        // TODO (Phase Inventory):
        // - stock opname finalized
        // - cost layers already used
        // - sales COGS generated from movement
        // - fiscal year closed handled by Phase 4F/date guard
        return false;
    }

    public function blockingReasons(mixed $transaction, string $action, string $module): array
    {
        return [];
    }
}

