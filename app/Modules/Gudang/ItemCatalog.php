<?php

namespace App\Modules\Gudang;

use App\Shared\Subscription\PlanOwnerResolver;
use App\Shared\Subscription\SubscriptionService;
use App\Shared\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ItemCatalog
{
    public function __construct(private TenantContext $tenant, private PlanOwnerResolver $owners, private SubscriptionService $subscriptions) {}

    public function save(array $raw, ?int $id = null): array
    {
        $values = Validator::make($raw, [
            'sku' => 'required|string|max:64', 'name' => 'required|string|max:160', 'unit' => 'required|string|max:24',
            'category' => 'nullable|string|max:100', 'minimum' => ['sometimes', 'numeric', 'regex:/^\d{1,8}(\.\d{1,3})?$/D'],
            'barcodes' => 'sometimes|array|max:20', 'barcodes.*' => 'required|string|max:100|distinct',
        ])->validate();
        $db = DB::connection('gudang');

        return $db->transaction(function () use ($db, $values, $id) {
            $db->table('gudang_warehouses')->orderBy('id')->lockForUpdate()->firstOrFail();
            $items = $db->table('gudang_items');
            $sku = mb_strtoupper(trim($values['sku']));
            $codes = array_map(fn ($code) => mb_strtoupper(trim($code)), $values['barcodes'] ?? []);
            $lookup = array_unique([$sku, ...$codes]);
            $duplicate = $db->table('gudang_barcodes')->whereIn('code', $lookup)->when($id, fn ($q) => $q->where('item_id', '<>', $id))->exists()
                || $db->table('gudang_items')->whereIn('sku', $lookup)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
            if ($duplicate || count(array_unique($codes)) !== count($codes)) {
                throw ValidationException::withMessages(['sku' => 'SKU atau barcode sudah digunakan.']);
            }
            $item = ['sku' => $sku, 'name' => $values['name'], 'unit' => $values['unit']];
            if (array_key_exists('category', $values)) {
                $item['category'] = $values['category'];
            }
            if (isset($values['minimum'])) {
                $item['minimum_milli'] = StockLedger::milli($values['minimum']);
            }
            if ($id) {
                $existing = $db->table('gudang_items')->where('id', $id)->firstOrFail();
                if ($existing->unit !== $item['unit'] && ($db->table('gudang_stock_movements')->where('item_id', $id)->exists() || $db->table('gudang_doc_lines')->where('item_id', $id)->exists())) {
                    throw ValidationException::withMessages(['unit' => 'Satuan barang sudah dipakai pada dokumen.']);
                }
                $items->where('id', $id)->update($item);
            } else {
                $owner = $this->owners->ownerOf($this->tenant->company());
                $limit = $this->subscriptions->summaryFor($owner, 'gudang')['limits']['products'];
                if ($limit !== null && $items->count() >= $limit) {
                    throw ValidationException::withMessages(['sku' => 'Batas barang paket Gudang sudah tercapai.']);
                }
                $id = $items->insertGetId($item);
            }
            if (array_key_exists('barcodes', $values)) {
                $db->table('gudang_barcodes')->where('item_id', $id)->delete();
                foreach ($codes as $code) {
                    $db->table('gudang_barcodes')->insert(['item_id' => $id, 'code' => $code]);
                }
            }

            return ['id' => $id] + $item;
        }, 3);
    }
}
