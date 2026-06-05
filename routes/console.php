<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\Inventory\StockBalanceRebuildService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('inventory:rebuild-stock-balances {--all : Rebuild all stock balances} {--product-id= : Rebuild balances for a product} {--warehouse-id= : Rebuild balances for a warehouse}', function () {
    /** @var StockBalanceRebuildService $svc */
    $svc = app(StockBalanceRebuildService::class);

    $productId = $this->option('product-id') ? (int) $this->option('product-id') : null;
    $warehouseId = $this->option('warehouse-id') ? (int) $this->option('warehouse-id') : null;
    $all = (bool) $this->option('all');

    if ($productId && $warehouseId) {
        $svc->rebuildProductWarehouse($productId, $warehouseId);
        $this->info('Stock balances rebuilt for product '.$productId.' and warehouse '.$warehouseId.'.');
        return;
    }

    if ($productId) {
        $svc->rebuildProduct($productId);
        $this->info('Stock balances rebuilt for product '.$productId.'.');
        return;
    }

    if ($warehouseId) {
        $svc->rebuildWarehouse($warehouseId);
        $this->info('Stock balances rebuilt for warehouse '.$warehouseId.'.');
        return;
    }

    if (! $all) {
        $this->warn('No filter provided; defaulting to --all.');
    }

    $svc->rebuildAll();
    $this->info('Stock balances rebuilt for all products and warehouses.');
})->purpose('Rebuild stock_balances from posted stock movements (internal)');
