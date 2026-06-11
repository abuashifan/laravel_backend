<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\VendorBill;
use App\Services\Purchase\PurchaseAccountResolverService;

class InventoryPurchaseIntegrationService
{
    public function __construct(
        private readonly StockMovementService $stockMovementService,
        private readonly PurchaseAccountResolverService $accountResolver,
    ) {}

    public function createPurchaseInFromGoodsReceipt(GoodsReceipt $goodsReceipt): ?StockMovement
    {
        $goodsReceipt->loadMissing('lines');
        $existing = StockMovement::query()
            ->where('source_type', 'goods_receipt')
            ->where('source_id', (int) $goodsReceipt->id)
            ->whereIn('status', ['draft', 'posted'])
            ->first();
        if ($existing) return $existing;

        $lines = [];
        foreach ($goodsReceipt->lines as $ln) {
            if (! $ln->product_id || ! $ln->warehouse_id) continue;
            $product = Product::query()->findOrFail((int) $ln->product_id);
            if (! (bool) $product->is_stock_item) continue;

            $unitCost = 0.0;
            if ($ln->purchase_order_line_id) {
                $poLine = PurchaseOrderLine::query()->find($ln->purchase_order_line_id);
                if ($poLine) $unitCost = (float) $poLine->unit_price;
            }

            $lines[] = [
                'product_id' => (int) $ln->product_id,
                'inventory_account_id' => $this->accountResolver->getInventoryAccountIdForLine(['product_id' => $ln->product_id]),
                'warehouse_id' => (int) $ln->warehouse_id,
                'unit_id' => (int) $ln->unit_id,
                'quantity' => (float) $ln->quantity,
                'unit_cost' => $unitCost,
                'department_id' => $ln->department_id,
                'project_id' => $ln->project_id,
                'source_line_type' => 'goods_receipt_line',
                'source_line_id' => (int) $ln->id,
                'sort_order' => (int) ($ln->sort_order ?? 0),
            ];
        }

        if ($lines === []) return null;

        return $this->stockMovementService->createAndPost([
            'movement_date' => (string) $goodsReceipt->receipt_date,
            'movement_type' => 'purchase_in',
            'source_type' => 'goods_receipt',
            'source_id' => (int) $goodsReceipt->id,
            'source_number' => $goodsReceipt->receipt_number ?? null,
            'source_revision' => $goodsReceipt->revision_no ?? null,
            'description' => 'Purchase in from goods receipt '.($goodsReceipt->receipt_number ?? $goodsReceipt->id),
            'lines' => $lines,
        ]);
    }

    public function createPurchaseInFromVendorBill(VendorBill $bill): ?StockMovement
    {
        if (! $this->shouldCreateStockFromVendorBill($bill)) return null;

        $bill->loadMissing('lines');
        $existing = StockMovement::query()
            ->where('source_type', 'vendor_bill')
            ->where('source_id', (int) $bill->id)
            ->whereIn('status', ['draft', 'posted'])
            ->first();
        if ($existing) return $existing;

        $lines = [];
        foreach ($bill->lines as $ln) {
            if (! $ln->product_id || ! $ln->warehouse_id) continue;
            $product = Product::query()->findOrFail((int) $ln->product_id);
            if (! (bool) $product->is_stock_item) continue;
            $lines[] = [
                'product_id' => (int) $ln->product_id,
                'inventory_account_id' => $this->accountResolver->getInventoryAccountIdForLine($ln),
                'warehouse_id' => (int) $ln->warehouse_id,
                'unit_id' => (int) $ln->unit_id,
                'quantity' => (float) $ln->quantity,
                'unit_cost' => (float) ($ln->unit_price ?? 0),
                'department_id' => $ln->department_id,
                'project_id' => $ln->project_id,
                'source_line_type' => 'vendor_bill_line',
                'source_line_id' => (int) $ln->id,
                'sort_order' => (int) ($ln->sort_order ?? 0),
            ];
        }

        if ($lines === []) return null;

        return $this->stockMovementService->createAndPost([
            'movement_date' => (string) $bill->bill_date,
            'movement_type' => 'purchase_in',
            'source_type' => 'vendor_bill',
            'source_id' => (int) $bill->id,
            'source_number' => $bill->bill_number ?? null,
            'source_revision' => $bill->revision_no ?? null,
            'description' => 'Purchase in from vendor bill '.($bill->bill_number ?? $bill->id),
            'lines' => $lines,
        ]);
    }

    public function createPurchaseReturnOut(PurchaseReturn $return): ?StockMovement
    {
        $return->loadMissing('lines');
        $existing = StockMovement::query()
            ->where('source_type', 'purchase_return')
            ->where('source_id', (int) $return->id)
            ->whereIn('status', ['draft', 'posted'])
            ->first();
        if ($existing) return $existing;

        $lines = [];
        foreach ($return->lines as $ln) {
            if (! $ln->product_id || ! $ln->warehouse_id) continue;
            $product = Product::query()->findOrFail((int) $ln->product_id);
            if (! (bool) $product->is_stock_item) continue;
            $lines[] = [
                'product_id' => (int) $ln->product_id,
                'inventory_account_id' => $this->accountResolver->getInventoryAccountIdForLine(['product_id' => $ln->product_id]),
                'warehouse_id' => (int) $ln->warehouse_id,
                'unit_id' => (int) $ln->unit_id,
                'quantity' => (float) $ln->quantity,
                'unit_cost' => 0,
                'department_id' => $ln->department_id,
                'project_id' => $ln->project_id,
                'source_line_type' => 'purchase_return_line',
                'source_line_id' => (int) $ln->id,
                'sort_order' => (int) ($ln->sort_order ?? 0),
            ];
        }

        if ($lines === []) return null;

        return $this->stockMovementService->createAndPost([
            'movement_date' => (string) $return->return_date,
            'movement_type' => 'purchase_return_out',
            'source_type' => 'purchase_return',
            'source_id' => (int) $return->id,
            'source_number' => $return->return_number ?? null,
            'source_revision' => $return->revision_no ?? null,
            'description' => 'Purchase return stock out '.($return->return_number ?? $return->id),
            'lines' => $lines,
        ]);
    }

    public function shouldCreateStockFromVendorBill(VendorBill $bill): bool
    {
        if (! (bool) config('inventory.allow_vendor_bill_direct_stock_receipt', true)) return false;
        if (! empty($bill->goods_receipt_id)) return false;
        return true;
    }
}
