<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\StockMovement;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Tenant\TenantContext;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Support\Facades\DB;

class StockMovementJournalService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly InventoryAccountMappingService $mappingService,
    ) {
    }

    public function createInventoryJournalForMovement(StockMovement $movement): ?JournalEntry
    {
        return match ((string) $movement->movement_type) {
            'purchase_in' => $this->createPurchaseInJournal($movement),
            'purchase_return_out' => $this->createPurchaseReturnOutJournal($movement),
            'sales_out' => $this->createCogsJournalForSalesOut($movement),
            'sales_return_in' => $this->createReturnJournal($movement),
            'adjustment_in', 'adjustment_out' => $this->createAdjustmentJournal($movement),
            'opening_stock' => $this->createOpeningStockJournal($movement),
            default => null,
        };
    }

    public function createPurchaseInJournal(StockMovement $movement): ?JournalEntry
    {
        $inventory = $this->mappingService->getInventoryAccount();
        $interim = $this->mappingService->getInventoryInterimAccount();
        if (! $interim) {
            // Interim mapping is optional; skip journal when not configured.
            return null;
        }

        return $this->createSimpleJournal($movement, [
            ['account_id' => $inventory, 'description' => 'Inventory', 'debit' => (float) $movement->total_value, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $interim, 'description' => 'Inventory Interim', 'debit' => 0, 'credit' => (float) $movement->total_value, 'line_order' => 2],
        ], 'Inventory receipt journal');
    }

    public function createPurchaseReturnOutJournal(StockMovement $movement): ?JournalEntry
    {
        $inventory = $this->mappingService->getInventoryAccount();
        $return = $this->mappingService->getPurchaseReturnAccount();
        if (! $return) {
            return null;
        }

        return $this->createSimpleJournal($movement, [
            ['account_id' => $return, 'description' => 'Purchase Return', 'debit' => (float) $movement->total_value, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $inventory, 'description' => 'Inventory', 'debit' => 0, 'credit' => (float) $movement->total_value, 'line_order' => 2],
        ], 'Inventory purchase return journal');
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
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($company, $movement, $lines, $desc) {
            $journal = JournalEntry::query()->create([
                'journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, (string) $movement->movement_date),
                'journal_date' => $movement->movement_date,
                'description' => $desc.' '.$movement->movement_number,
                'status' => 'posted',
                'revision_no' => 1,
                'source_type' => 'stock_movement',
                'source_id' => $movement->id,
                'source_number' => $movement->movement_number,
                'source_revision' => 1,
                'source_module' => 'inventory',
                'is_system_generated' => true,
                'created_by' => auth()->id(),
                'posted_by' => auth()->id(),
                'posted_at' => now(),
            ]);

            $journal->lines()->createMany($lines);
            return $journal->refresh();
        });
    }
}
