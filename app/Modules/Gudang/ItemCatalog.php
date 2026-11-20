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
            'batch_enabled' => 'sometimes|boolean', 'abc_override' => 'nullable|in:A,B,C',
            'lead_days' => 'sometimes|integer|min:0|max:3650',
            'safety' => ['sometimes', 'numeric', 'regex:/^\d{1,8}(\.\d{1,3})?$/D'],
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
            foreach (['batch_enabled', 'abc_override', 'lead_days'] as $field) {
                if (array_key_exists($field, $values)) {
                    $item[$field] = $values[$field];
                }
            }
            if (isset($values['safety'])) {
                $item['safety_milli'] = StockLedger::milli($values['safety']);
            }
            if ($id) {
                $existing = $db->table('gudang_items')->where('id', $id)->lockForUpdate()->firstOrFail();
                if (array_key_exists('batch_enabled', $item) && (bool) $item['batch_enabled'] !== (bool) $existing->batch_enabled) {
                    if ($db->table('gudang_stock_balances')->where('item_id', $id)->where('reserved_milli', '>', 0)->exists()) {
                        throw ValidationException::withMessages(['batch_enabled' => 'Selesaikan reservasi aktif sebelum mengubah pelacakan batch.']);
                    }
                    $db->table('gudang_locations')->orderBy('id')->lockForUpdate()->get(['id']);
                    if ($db->table('gudang_count_lines as line')->join('gudang_counts as count', 'count.id', '=', 'line.count_id')
                        ->where('line.item_id', $id)->whereIn('count.status', ['counting', 'pending'])->exists()) {
                        throw ValidationException::withMessages(['batch_enabled' => 'Selesaikan penghitungan aktif sebelum mengubah pelacakan batch.']);
                    }
                    if ($db->table('gudang_doc_lines as line')->join('gudang_stock_documents as doc', 'doc.id', '=', 'line.document_id')
                        ->where('line.item_id', $id)->whereIn('doc.status', ['draft', 'ordered', 'partial', 'pending', 'transit', 'disputed'])->exists()) {
                        throw ValidationException::withMessages(['batch_enabled' => 'Selesaikan dokumen aktif sebelum mengubah pelacakan batch.']);
                    }
                    if (! $item['batch_enabled'] && $db->table('gudang_batches')->where('item_id', $id)->exists()) {
                        throw ValidationException::withMessages(['batch_enabled' => 'Pelacakan batch yang sudah memiliki batch dipertahankan untuk keterlacakan.']);
                    }
                    if ($item['batch_enabled']) {
                        app(BatchStock::class)->enable($existing, auth()->id());
                    }
                }
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
