<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosGudangReceipt;
use App\Modules\Pos\Models\PosProduct;
use App\Shared\Models\Company;
use App\Shared\Tenant\TenantConnectionManager;
use App\Shared\Tenant\TenantProductService;
use App\Shared\Warehouse\OutletReceivingGateway;

/** Sisi Kasir dari jembatan Gudang -> Kasir: membaca dan menulis tenant Kasir atas nama Gudang. */
class PosGudangReceivingGateway implements OutletReceivingGateway
{
    public function __construct(
        private readonly TenantProductService $products,
        private readonly TenantConnectionManager $connections,
        private readonly PosGudangReceiptService $receipts,
    ) {}

    public function available(Company $company): bool
    {
        return $this->connect($company);
    }

    public function match(Company $company, array $items): array
    {
        if ($items === [] || ! $this->connect($company)) {
            return [];
        }
        $skus = array_column($items, 'sku');
        $codes = [];
        foreach ($items as $item) {
            foreach ($item['barcodes'] as $code) {
                array_push($codes, $code, mb_strtoupper($code), mb_strtolower($code));
            }
        }
        $found = PosProduct::query()
            ->where(fn ($query) => $query->whereIn('sku', $skus)->when($codes !== [], fn ($q) => $q->orWhereIn('barcode', array_values(array_unique($codes)))))
            ->get(['id', 'sku', 'barcode', 'name']);

        $result = [];
        foreach ($items as $item) {
            $upper = array_map('mb_strtoupper', $item['barcodes']);
            // Barcode lebih spesifik daripada SKU, jadi dicoba lebih dulu.
            $product = $found->first(fn (PosProduct $p) => $p->barcode !== null && in_array(mb_strtoupper($p->barcode), $upper, true))
                ?? $found->first(fn (PosProduct $p) => $p->sku === $item['sku']);
            if ($product) {
                $result[$item['sku']] = ['product_id' => (int) $product->id, 'name' => (string) $product->name];
            }
        }

        return $result;
    }

    public function importProducts(Company $company, array $items): array
    {
        $created = 0;
        $skipped = 0;
        if (! $this->connect($company)) {
            return compact('created', 'skipped');
        }
        foreach ($items as $item) {
            $barcode = $item['barcode'] !== null && $item['barcode'] !== '' ? $item['barcode'] : null;
            $taken = PosProduct::query()->where('sku', $item['sku'])->when($barcode, fn ($q) => $q->orWhere('barcode', $barcode))->exists();
            // Kolom SKU Kasir 50 karakter; SKU lebih panjang tidak bisa dicocokkan, jadi tidak diimpor.
            if ($taken || mb_strlen($item['sku']) > 50) {
                $skipped++;

                continue;
            }
            PosProduct::query()->create([
                'sku' => $item['sku'], 'barcode' => $barcode, 'name' => mb_substr($item['name'], 0, 150),
                'unit' => mb_substr($item['unit'], 0, 20), 'price' => 0, 'cost' => 0, 'track_stock' => true, 'stock' => 0, 'is_active' => true,
            ]);
            $created++;
        }

        return compact('created', 'skipped');
    }

    public function deliver(Company $company, array $shipment): bool
    {
        if (! $this->connect($company)) {
            return false;
        }
        $this->receipts->deliver($shipment);

        return true;
    }

    public function statuses(Company $company, array $keys): array
    {
        if ($keys === [] || ! $this->connect($company)) {
            return [];
        }

        return PosGudangReceipt::query()->with('lines')->whereIn('receipt_key', $keys)->get()->mapWithKeys(fn (PosGudangReceipt $receipt) => [
            (string) $receipt->receipt_key => [
                'status' => $receipt->status,
                'received_at' => $receipt->received_at?->toISOString(),
                'received_by' => $receipt->received_by_name,
                'note' => $receipt->note,
                'lines' => $receipt->lines->map(fn ($line) => [
                    'sku' => $line->sku,
                    'expected' => rtrim(rtrim(number_format((float) $line->expected_quantity, 3, '.', ''), '0'), '.'),
                    'actual' => $line->actual_quantity === null ? null : rtrim(rtrim(number_format((float) $line->actual_quantity, 3, '.', ''), '0'), '.'),
                ])->all(),
            ],
        ])->all();
    }

    /** Sambungkan tenant Kasir milik perusahaan; false bila belum aktif. */
    private function connect(Company $company): bool
    {
        $tenant = $this->products->find($company, 'pos');
        if (! $tenant || $tenant->status !== 'active') {
            return false;
        }
        $this->connections->connect($tenant);

        return true;
    }
}
