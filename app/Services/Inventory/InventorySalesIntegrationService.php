<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\Tenant\DeliveryOrder;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\StockMovement;

class InventorySalesIntegrationService
{
    public function __construct(
        private readonly StockMovementService $stockMovementService,
        private readonly StockBalanceService $stockBalanceService,
    ) {
    }

    public function createSalesOutFromDeliveryOrder(DeliveryOrder $deliveryOrder): ?StockMovement
    {
        $deliveryOrder->loadMissing('lines');
        $existing = StockMovement::query()
            ->where('source_type', 'delivery_order')
            ->where('source_id', (int) $deliveryOrder->id)
            ->whereIn('status', ['draft', 'posted'])
            ->first();
        if ($existing) return $existing;

        $lines = [];
        foreach ($deliveryOrder->lines as $ln) {
            if (! $ln->product_id || ! $ln->warehouse_id) continue;
            $product = Product::query()->findOrFail((int) $ln->product_id);
            if (! (bool) $product->is_stock_item) continue;
            $lines[] = [
                'product_id' => (int) $ln->product_id,
                'warehouse_id' => (int) $ln->warehouse_id,
                'unit_id' => (int) $ln->unit_id,
                'quantity' => (float) $ln->quantity,
                'unit_cost' => 0,
                'department_id' => $ln->department_id,
                'project_id' => $ln->project_id,
                'source_line_type' => 'delivery_order_line',
                'source_line_id' => (int) $ln->id,
                'sort_order' => (int) ($ln->sort_order ?? 0),
            ];
        }

        if ($lines === []) return null;

        return $this->stockMovementService->createAndPost([
            'movement_date' => (string) $deliveryOrder->delivery_date,
            'movement_type' => 'sales_out',
            'source_type' => 'delivery_order',
            'source_id' => (int) $deliveryOrder->id,
            'source_number' => $deliveryOrder->delivery_number ?? null,
            'source_revision' => $deliveryOrder->revision_no ?? null,
            'description' => 'Sales out from delivery order '.($deliveryOrder->delivery_number ?? $deliveryOrder->id),
            'lines' => $lines,
        ]);
    }

    public function createSalesOutFromSalesInvoice(SalesInvoice $invoice): ?StockMovement
    {
        if (! $this->shouldCreateStockFromSalesInvoice($invoice)) return null;

        $invoice->loadMissing('lines');
        $existing = StockMovement::query()
            ->where('source_type', 'sales_invoice')
            ->where('source_id', (int) $invoice->id)
            ->whereIn('status', ['draft', 'posted'])
            ->first();
        if ($existing) return $existing;

        $lines = [];
        foreach ($invoice->lines as $ln) {
            if (! $ln->product_id || ! $ln->warehouse_id) continue;
            $product = Product::query()->findOrFail((int) $ln->product_id);
            if (! (bool) $product->is_stock_item) continue;
            $lines[] = [
                'product_id' => (int) $ln->product_id,
                'warehouse_id' => (int) $ln->warehouse_id,
                'unit_id' => (int) $ln->unit_id,
                'quantity' => (float) $ln->quantity,
                'unit_cost' => 0,
                'department_id' => $ln->department_id,
                'project_id' => $ln->project_id,
                'source_line_type' => 'sales_invoice_line',
                'source_line_id' => (int) $ln->id,
                'sort_order' => (int) ($ln->sort_order ?? 0),
            ];
        }

        if ($lines === []) return null;

        return $this->stockMovementService->createAndPost([
            'movement_date' => (string) $invoice->invoice_date,
            'movement_type' => 'sales_out',
            'source_type' => 'sales_invoice',
            'source_id' => (int) $invoice->id,
            'source_number' => $invoice->invoice_number ?? null,
            'source_revision' => $invoice->revision_no ?? null,
            'description' => 'Sales out from sales invoice '.($invoice->invoice_number ?? $invoice->id),
            'lines' => $lines,
        ]);
    }

    public function createSalesReturnIn(SalesReturn $return): ?StockMovement
    {
        $return->loadMissing('lines');
        $existing = StockMovement::query()
            ->where('source_type', 'sales_return')
            ->where('source_id', (int) $return->id)
            ->whereIn('status', ['draft', 'posted'])
            ->first();
        if ($existing) return $existing;

        $lines = [];
        foreach ($return->lines as $ln) {
            if (! $ln->product_id || ! $ln->warehouse_id) continue;
            $product = Product::query()->findOrFail((int) $ln->product_id);
            if (! (bool) $product->is_stock_item) continue;

            $balance = $this->stockBalanceService->getOrCreateBalance((int) $ln->product_id, (int) $ln->warehouse_id);
            $unitCost = (float) $balance->average_cost;

            $lines[] = [
                'product_id' => (int) $ln->product_id,
                'warehouse_id' => (int) $ln->warehouse_id,
                'unit_id' => (int) $ln->unit_id,
                'quantity' => (float) $ln->quantity,
                'unit_cost' => $unitCost,
                'department_id' => $ln->department_id,
                'project_id' => $ln->project_id,
                'source_line_type' => 'sales_return_line',
                'source_line_id' => (int) $ln->id,
                'sort_order' => (int) ($ln->sort_order ?? 0),
            ];
        }

        if ($lines === []) return null;

        return $this->stockMovementService->createAndPost([
            'movement_date' => (string) $return->return_date,
            'movement_type' => 'sales_return_in',
            'source_type' => 'sales_return',
            'source_id' => (int) $return->id,
            'source_number' => $return->return_number ?? null,
            'source_revision' => $return->revision_no ?? null,
            'description' => 'Sales return stock in '.($return->return_number ?? $return->id),
            'lines' => $lines,
        ]);
    }

    public function shouldCreateStockFromSalesInvoice(SalesInvoice $invoice): bool
    {
        if (! empty($invoice->delivery_order_id)) return false;
        return true;
    }
}
