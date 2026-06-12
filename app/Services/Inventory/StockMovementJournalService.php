<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\StockMovement;
use App\Services\Journal\SystemJournalBuilder;

class StockMovementJournalService
{
    public function __construct(
        private readonly InventoryAccountMappingService $mappingService,
        private readonly SystemJournalBuilder $journalBuilder,
    ) {
    }

    public function createInventoryJournalForMovement(StockMovement $movement): ?JournalEntry
    {
        return match ((string) $movement->movement_type) {
            'purchase_in' => $this->createPurchaseInJournal($movement),
            'purchase_return_out' => $this->createPurchaseReturnOutJournal($movement),
            'sales_out' => $this->createCogsJournalForSalesOut($movement),
            'sales_return_in' => $this->createReturnJournal($movement),
            'opname_in', 'opname_out' => $this->createStockOpnameJournal($movement),
            'adjustment_in', 'adjustment_out' => $this->createAdjustmentJournal($movement),
            'opening_stock' => $this->createOpeningStockJournal($movement),
            default => null,
        };
    }

    public function createPurchaseInJournal(StockMovement $movement): ?JournalEntry
    {
        if ($movement->source_type === 'vendor_bill') {
            return null;
        }

        $interim = $this->mappingService->getInventoryInterimAccount();
        if (! $interim) {
            $message = $this->mappingService->missingMappingMessage('purchase.inventory_interim');
            throw ApiException::make('ACCOUNT_MAPPING_MISSING', $message, 422, ['account_mapping' => [$message]]);
        }

        $lines = $this->inventoryDebitLines($movement);
        $lines[] = [
            'account_id' => $interim,
            'description' => 'Inventory Interim',
            'debit' => 0,
            'credit' => (float) $movement->total_value,
            'line_order' => count($lines) + 1,
        ];

        return $this->createSimpleJournal($movement, $lines, 'Inventory receipt journal');
    }

    public function createPurchaseReturnOutJournal(StockMovement $movement): ?JournalEntry
    {
        $return = $this->mappingService->getPurchaseReturnAccount();
        if (! $return) {
            return null;
        }

        $lines = [
            ['account_id' => $return, 'description' => 'Purchase Return', 'debit' => (float) $movement->total_value, 'credit' => 0, 'line_order' => 1],
        ];
        foreach ($this->inventoryCreditLines($movement, 2) as $line) {
            $lines[] = $line;
        }

        return $this->createSimpleJournal($movement, $lines, 'Inventory purchase return journal');
    }

    public function createCogsJournalForSalesOut(StockMovement $movement): ?JournalEntry
    {
        $inventory = $this->mappingService->getInventoryAccount();
        $cogs = $this->mappingService->getCogsAccount();

        return $this->createSimpleJournal($movement, [
            ['account_id' => $cogs, 'description' => 'COGS', 'debit' => (float) $movement->total_value, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $inventory, 'description' => 'Inventory', 'debit' => 0, 'credit' => (float) $movement->total_value, 'line_order' => 2],
        ], 'COGS journal for sales out');
    }

    public function createReturnJournal(StockMovement $movement): ?JournalEntry
    {
        $inventory = $this->mappingService->getInventoryAccount();
        $cogs = $this->mappingService->getCogsAccount();

        return $this->createSimpleJournal($movement, [
            ['account_id' => $inventory, 'description' => 'Inventory', 'debit' => (float) $movement->total_value, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $cogs, 'description' => 'COGS', 'debit' => 0, 'credit' => (float) $movement->total_value, 'line_order' => 2],
        ], 'Reverse COGS journal for sales return');
    }

    public function createAdjustmentJournal(StockMovement $movement): ?JournalEntry
    {
        if (abs((float) $movement->total_value) < PHP_FLOAT_EPSILON) {
            return null;
        }

        $inventory = $this->mappingService->getInventoryAccount();

        if ($movement->movement_type === 'adjustment_in') {
            $gain = $this->mappingService->getStockAdjustmentGainAccount();
            if (! $gain) {
                $message = $this->mappingService->missingMappingMessage('inventory.adjustment_gain');
                throw ApiException::make('ACCOUNT_MAPPING_MISSING', $message, 422, ['account_mapping' => [$message]]);
            }
            return $this->createSimpleJournal($movement, [
                ['account_id' => $inventory, 'description' => 'Inventory', 'debit' => (float) $movement->total_value, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $gain, 'description' => 'Stock Adjustment Gain', 'debit' => 0, 'credit' => (float) $movement->total_value, 'line_order' => 2],
            ], 'Inventory adjustment in journal');
        }

        $loss = $this->mappingService->getStockAdjustmentLossAccount();
        if (! $loss) {
            $message = $this->mappingService->missingMappingMessage('inventory.adjustment_loss');
            throw ApiException::make('ACCOUNT_MAPPING_MISSING', $message, 422, ['account_mapping' => [$message]]);
        }
        return $this->createSimpleJournal($movement, [
            ['account_id' => $loss, 'description' => 'Stock Adjustment Loss', 'debit' => (float) $movement->total_value, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $inventory, 'description' => 'Inventory', 'debit' => 0, 'credit' => (float) $movement->total_value, 'line_order' => 2],
        ], 'Inventory adjustment out journal');
    }

    public function createStockOpnameJournal(StockMovement $movement): JournalEntry
    {
        $movementType = (string) $movement->movement_type;
        $direction = (string) $movement->direction;

        if (! in_array($direction, ['in', 'out'], true)) {
            throw ApiException::make('INVALID_STOCK_MOVEMENT_DIRECTION', 'Invalid stock opname movement direction.', 422);
        }

        $offsetAccount = $movementType === 'opname_in'
            ? $this->mappingService->getStockAdjustmentGainAccount()
            : $this->mappingService->getStockAdjustmentLossAccount();

        if (! $offsetAccount) {
            $key = $movementType === 'opname_in' ? 'inventory.adjustment_gain' : 'inventory.adjustment_loss';
            $message = $this->mappingService->missingMappingMessage($key);
            throw ApiException::make('ACCOUNT_MAPPING_MISSING', $message, 422, ['account_mapping' => [$message]]);
        }

        if ($movementType === 'opname_in') {
            if ($direction === 'in') {
                $lines = $this->opnameInventoryDebitLines($movement);
                array_push($lines, ...$this->opnameOffsetLines($movement, $offsetAccount, 'credit', 'Stock Opname Adjustment Gain', count($lines) + 1));

                return $this->createSimpleJournal($movement, $lines, 'Stock Opname Increase -');
            }

            $lines = $this->opnameOffsetLines($movement, $offsetAccount, 'debit', 'Reverse Stock Opname Adjustment Gain', 1);
            array_push($lines, ...$this->opnameInventoryCreditLines($movement, count($lines) + 1));

            return $this->createSimpleJournal($movement, $lines, 'Reversal Stock Opname Increase -');
        }

        if ($direction === 'out') {
            $lines = $this->opnameOffsetLines($movement, $offsetAccount, 'debit', 'Stock Opname Adjustment Loss', 1);
            array_push($lines, ...$this->opnameInventoryCreditLines($movement, count($lines) + 1));

            return $this->createSimpleJournal($movement, $lines, 'Stock Opname Decrease -');
        }

        $lines = $this->opnameInventoryDebitLines($movement);
        array_push($lines, ...$this->opnameOffsetLines($movement, $offsetAccount, 'credit', 'Reverse Stock Opname Adjustment Loss', count($lines) + 1));

        return $this->createSimpleJournal($movement, $lines, 'Reversal Stock Opname Decrease -');
    }

    public function createOpeningStockJournal(StockMovement $movement): ?JournalEntry
    {
        $inventory = $this->mappingService->getInventoryAccount();
        $equity = $this->mappingService->getOpeningStockEquityAccount();
        if (! $equity) {
            $message = $this->mappingService->missingMappingMessage('opening_balance.equity');
            throw ApiException::make('ACCOUNT_MAPPING_MISSING', $message, 422, ['account_mapping' => [$message]]);
        }

        return $this->createSimpleJournal($movement, [
            ['account_id' => $inventory, 'description' => 'Inventory', 'debit' => (float) $movement->total_value, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $equity, 'description' => 'Opening Stock Equity', 'debit' => 0, 'credit' => (float) $movement->total_value, 'line_order' => 2],
        ], 'Opening stock journal');
    }

    private function createSimpleJournal(StockMovement $movement, array $lines, string $desc): JournalEntry
    {
        return $this->journalBuilder->create([
            'source_type'     => 'stock_movement',
            'source_id'       => $movement->id,
            'source_number'   => $movement->movement_number,
            'source_revision' => 1,
            'source_module'   => 'inventory',
            'journal_date'    => (string) $movement->movement_date,
            'description'     => $desc.' '.$movement->movement_number,
        ], $lines);
    }

    private function inventoryDebitLines(StockMovement $movement, int $startOrder = 1): array
    {
        $movement->loadMissing('lines');

        return $movement->lines
            ->groupBy(fn ($line): int => (int) ($line->inventory_account_id ?: $this->mappingService->getInventoryAccount()))
            ->values()
            ->map(function ($lines, int $index) use ($startOrder): array {
                return [
                    'account_id' => (int) ($lines->first()->inventory_account_id ?: $this->mappingService->getInventoryAccount()),
                    'description' => 'Inventory',
                    'debit' => round((float) $lines->sum('total_cost'), 2),
                    'credit' => 0,
                    'line_order' => $startOrder + $index,
                ];
            })
            ->all();
    }

    private function opnameInventoryDebitLines(StockMovement $movement, int $startOrder = 1): array
    {
        return $this->opnameInventoryLines($movement, 'debit', $startOrder);
    }

    private function opnameInventoryCreditLines(StockMovement $movement, int $startOrder = 1): array
    {
        return $this->opnameInventoryLines($movement, 'credit', $startOrder);
    }

    private function opnameInventoryLines(StockMovement $movement, string $side, int $startOrder): array
    {
        $movement->loadMissing('lines.product');

        return $movement->lines
            ->groupBy(function ($line): string {
                return implode('|', [
                    $this->resolveOpnameInventoryAccountId($line),
                    $line->department_id ?: '',
                    $line->project_id ?: '',
                ]);
            })
            ->values()
            ->map(function ($lines, int $index) use ($side, $startOrder): array {
                $accountId = $this->resolveOpnameInventoryAccountId($lines->first());
                $amount = round((float) $lines->sum('total_cost'), 2);

                return [
                    'account_id' => $accountId,
                    'description' => 'Inventory',
                    'debit' => $side === 'debit' ? $amount : 0,
                    'credit' => $side === 'credit' ? $amount : 0,
                    'department_id' => $lines->first()->department_id,
                    'project_id' => $lines->first()->project_id,
                    'line_order' => $startOrder + $index,
                ];
            })
            ->all();
    }

    private function opnameOffsetLines(StockMovement $movement, int $accountId, string $side, string $description, int $startOrder): array
    {
        $movement->loadMissing('lines');

        return $movement->lines
            ->groupBy(fn ($line): string => implode('|', [$line->department_id ?: '', $line->project_id ?: '']))
            ->values()
            ->map(function ($lines, int $index) use ($accountId, $side, $description, $startOrder): array {
                $amount = round((float) $lines->sum('total_cost'), 2);

                return [
                    'account_id' => $accountId,
                    'description' => $description,
                    'debit' => $side === 'debit' ? $amount : 0,
                    'credit' => $side === 'credit' ? $amount : 0,
                    'department_id' => $lines->first()->department_id,
                    'project_id' => $lines->first()->project_id,
                    'line_order' => $startOrder + $index,
                ];
            })
            ->all();
    }

    private function resolveOpnameInventoryAccountId($line): int
    {
        if ($line->inventory_account_id) {
            return (int) $line->inventory_account_id;
        }

        $product = $line->relationLoaded('product') ? $line->product : $line->product()->first();
        if ($product?->inventory_account_id) {
            return (int) $product->inventory_account_id;
        }

        return $this->mappingService->getInventoryAccount();
    }

    private function inventoryCreditLines(StockMovement $movement, int $startOrder = 1): array
    {
        $movement->loadMissing('lines');

        return $movement->lines
            ->groupBy(fn ($line): int => (int) ($line->inventory_account_id ?: $this->mappingService->getInventoryAccount()))
            ->values()
            ->map(function ($lines, int $index) use ($startOrder): array {
                return [
                    'account_id' => (int) ($lines->first()->inventory_account_id ?: $this->mappingService->getInventoryAccount()),
                    'description' => 'Inventory',
                    'debit' => 0,
                    'credit' => round((float) $lines->sum('total_cost'), 2),
                    'line_order' => $startOrder + $index,
                ];
            })
            ->all();
    }
}
