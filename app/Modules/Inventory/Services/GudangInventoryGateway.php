<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Models\Product;
use App\Modules\MasterData\Models\Warehouse;
use App\Modules\Purchase\Models\GoodsReceipt;
use App\Modules\Purchase\Services\GoodsReceiptService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use App\Shared\Tenant\TenantConnectionManager;
use App\Shared\Tenant\TenantProductService;
use App\Shared\Warehouse\InventoryAccountingGateway;
use Closure;

/** Sisi Accounting dari jembatan Gudang -> Accounting: menulis tenant Accounting atas nama Gudang. */
class GudangInventoryGateway implements InventoryAccountingGateway
{
    public const RECEIPT_SOURCE = 'gudang_receipt';

    public const ADJUSTMENT_SOURCE = 'gudang_adjustment';

    public function __construct(
        private readonly TenantProductService $products,
        private readonly TenantConnectionManager $connections,
    ) {}

    public function available(Company $company): bool
    {
        return $this->withAccounting($company, fn () => true) ?? false;
    }

    public function options(Company $company): ?array
    {
        return $this->withAccounting($company, fn (): array => [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (Warehouse $w) => ['id' => (int) $w->id, 'code' => $w->code, 'name' => (string) $w->name])->all(),
            'vendors' => Contact::query()->where('is_supplier', true)->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'name'])
                ->map(fn (Contact $c) => ['id' => (int) $c->id, 'name' => (string) $c->name])->all(),
        ]);
    }

    public function match(Company $company, array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        return $this->withAccounting($company, fn (): array => Product::query()
            ->whereIn('product_code', array_values(array_unique($skus)))
            ->where('is_active', true)->where('is_stock_item', true)
            ->get(['id', 'product_code', 'product_name'])
            ->mapWithKeys(fn (Product $p) => [(string) $p->product_code => ['product_id' => (int) $p->id, 'name' => (string) $p->product_name]])
            ->all()) ?? [];
    }

    public function postReceipt(Company $company, array $receipt): array
    {
        return $this->withAccounting($company, function () use ($receipt): array {
            $service = app(GoodsReceiptService::class);
            $existing = GoodsReceipt::query()->where('source_type', self::RECEIPT_SOURCE)->where('source_id', $receipt['source_id'])
                ->whereNotIn('status', ['void', 'cancelled'])->latest('id')->first();
            if (! $existing) {
                $vendor = Contact::query()->where('is_supplier', true)->where('is_active', true)
                    ->whereRaw('lower(name) = ?', [mb_strtolower(trim($receipt['supplier']))])->value('id') ?? $receipt['vendor_id'];
                $existing = $service->create([
                    'vendor_id' => (int) $vendor,
                    'receipt_date' => $receipt['date'],
                    'warehouse_id' => $receipt['warehouse_id'],
                    'source_type' => self::RECEIPT_SOURCE,
                    'source_id' => $receipt['source_id'],
                    'source_number' => $receipt['number'],
                    'notes' => 'Dari Finlite Gudang '.$receipt['number'].' · '.$receipt['supplier'],
                    'lines' => array_map(fn (array $line) => [
                        'product_id' => $line['product_id'],
                        'description' => $line['description'],
                        'quantity' => $line['quantity'],
                        'warehouse_id' => $receipt['warehouse_id'],
                        // Harga beli Gudang dipakai bila tidak ada PO di Accounting (lihat InventoryPurchaseIntegrationService).
                        'metadata' => $line['unit_cost'] !== null ? ['gudang_unit_cost' => $line['unit_cost']] : null,
                    ], $receipt['lines']),
                ]);
            }
            if ($existing->status === 'draft') {
                $existing = $service->receive($existing);
            }
            $journal = StockMovement::query()->where('source_type', 'goods_receipt')->where('source_id', $existing->id)
                ->with('journalEntry')->latest('id')->first()?->journalEntry?->journal_number;

            return ['reference' => implode(' · ', array_filter([$existing->receipt_number, $journal]))];
        }) ?? throw ApiException::make('ACCOUNTING_NOT_AVAILABLE', 'Perusahaan ini belum memakai Finlite Accounting.', 422);
    }

    public function postAdjustment(Company $company, array $adjustment): array
    {
        return $this->withAccounting($company, function () use ($adjustment): array {
            $movements = app(StockMovementService::class);
            $references = [];
            foreach (['adjustment_in' => 1, 'adjustment_out' => -1] as $type => $sign) {
                $lines = array_values(array_filter($adjustment['lines'], fn (array $line) => $line['quantity'] * $sign > 0));
                if ($lines === []) {
                    continue;
                }
                $movement = StockMovement::query()->where('source_type', self::ADJUSTMENT_SOURCE)->where('source_id', $adjustment['source_id'])
                    ->where('movement_type', $type)->whereIn('status', ['draft', 'posted'])->first();
                if (! $movement) {
                    $movement = $movements->createAndPost([
                        'movement_date' => $adjustment['date'],
                        'movement_type' => $type,
                        'source_type' => self::ADJUSTMENT_SOURCE,
                        'source_id' => $adjustment['source_id'],
                        'source_number' => $adjustment['number'],
                        'description' => $adjustment['description'],
                        'lines' => array_map(function (array $line) use ($adjustment, $sign) {
                            $product = Product::query()->findOrFail($line['product_id']);
                            // Barang masuk dinilai rata-rata persediaan saat ini; barang keluar dinilai oleh Accounting saat diposting.
                            $average = $sign > 0 ? (float) (StockBalance::query()->where('product_id', $product->id)
                                ->where('warehouse_id', $adjustment['warehouse_id'])->value('average_cost') ?? 0) : 0;

                            return [
                                'product_id' => (int) $product->id, 'warehouse_id' => $adjustment['warehouse_id'], 'unit_id' => $product->unit_id,
                                'quantity' => abs($line['quantity']), 'unit_cost' => $average,
                            ];
                        }, $lines),
                    ]);
                } elseif ($movement->status === 'draft') {
                    $movement = $movements->post($movement);
                }
                $movement->loadMissing('journalEntry');
                $references[] = implode(' · ', array_filter([$movement->movement_number, $movement->journalEntry?->journal_number]));
            }

            return ['reference' => implode('; ', $references)];
        }) ?? throw ApiException::make('ACCOUNTING_NOT_AVAILABLE', 'Perusahaan ini belum memakai Finlite Accounting.', 422);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|null
     */
    private function withAccounting(Company $company, Closure $callback): mixed
    {
        $tenant = $this->products->find($company, 'accounting');
        if (! $tenant || $tenant->status !== 'active') {
            return null;
        }
        $this->connections->connect($tenant);

        return $callback();
    }
}
