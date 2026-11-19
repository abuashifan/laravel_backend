<?php

namespace App\Modules\Gudang\Controllers;

use App\Modules\Gudang\ItemCatalog;
use App\Modules\Gudang\StockLedger;
use App\Modules\Gudang\WarehouseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends GudangController
{
    public function items(): JsonResponse
    {
        $db = DB::connection('gudang');
        $codes = $db->table('gudang_barcodes')->get()->groupBy('item_id');
        $units = $db->table('gudang_item_units')->orderBy('name')->get()->groupBy('item_id');
        $items = $db->table('gudang_items')->orderBy('name')->orderBy('id')->get();
        foreach ($items as $item) {
            $item->barcodes = $codes->get($item->id, collect())->pluck('code');
            $item->units = $units->get($item->id, collect());
        }

        return $this->successResponse($items);
    }

    public function createItem(Request $request, ItemCatalog $catalog): JsonResponse
    {
        return $this->successResponse($catalog->save($request->all()), 'Barang tersimpan.', 201);
    }

    public function updateItem(int $id, Request $request, ItemCatalog $catalog): JsonResponse
    {
        return $this->successResponse($catalog->save($request->all(), $id));
    }

    public function locations(WarehouseAccess $access): JsonResponse
    {
        $locations = DB::connection('gudang')->table('gudang_locations as l')
            ->join('gudang_warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->whereIn('l.warehouse_id', $access->warehouses())
            ->orderBy('w.name')->orderBy('l.name')->get(['l.*', 'w.name as warehouse_name']);

        return $this->successResponse($locations);
    }

    public function createLocation(Request $request, WarehouseAccess $access): JsonResponse
    {
        $location = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:gudang.gudang_warehouses,id'],
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer'],
            'kind' => ['sometimes', 'in:simpan,terima,karantina,rusak'],
            'barcode' => ['nullable', 'string', 'max:100', 'unique:gudang.gudang_locations,barcode'],
        ]);
        abort_unless($access->warehouses()->where('id', $location['warehouse_id'])->exists(), 403);
        $locations = DB::connection('gudang')->table('gudang_locations');
        if (! empty($location['parent_id'])) {
            abort_unless((clone $locations)->where('id', $location['parent_id'])->where('warehouse_id', $location['warehouse_id'])->exists(), 422, 'Induk lokasi harus satu gudang.');
        }
        if (! $locations->insertOrIgnore($location)) {
            throw ValidationException::withMessages(['name' => 'Nama lokasi sudah digunakan di gudang ini.']);
        }

        return $this->successResponse($locations->where($location)->firstOrFail(), 'Lokasi tersimpan.', 201);
    }

    public function receive(Request $request, StockLedger $ledger, WarehouseAccess $access): JsonResponse
    {
        $input = $request->validate([
            'request_key' => ['required', 'string', 'max:80'],
            'source' => ['required', 'string', 'max:160'],
            'lines' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'lines.*.item_id' => ['required', 'integer', 'exists:gudang.gudang_items,id'],
            'lines.*.location_id' => ['required', 'integer', 'exists:gudang.gudang_locations,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,8}(\.\d{1,3})?$/D'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:gudang.gudang_item_units,id'],
            'lines.*.batch_id' => ['nullable', 'integer', 'exists:gudang.gudang_batches,id'],
        ]);

        $access->requireLocations(array_column($input['lines'], 'location_id'));

        return $this->successResponse($ledger->receive($input, $this->user($request)->id), 'Penerimaan tercatat.');
    }

    public function balances(WarehouseAccess $access): JsonResponse
    {
        $balances = DB::connection('gudang')->table('gudang_stock_balances as b')
            ->join('gudang_items as i', 'i.id', '=', 'b.item_id')
            ->join('gudang_locations as l', 'l.id', '=', 'b.location_id')
            ->whereIn('l.warehouse_id', $access->warehouses())
            ->orderBy('i.name')->orderBy('l.name')
            ->get(['b.*', 'i.sku', 'i.name as item_name', 'i.unit', 'l.name as location_name']);

        return $this->successResponse($balances);
    }

    public function card(Request $request, int $item, WarehouseAccess $access): JsonResponse
    {
        $db = DB::connection('gudang');
        abort_unless($db->table('gudang_items')->where('id', $item)->exists(), 404);
        $filters = $request->validate(['location_id' => ['sometimes', 'integer'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $movements = $db->table('gudang_stock_movements as m')
            ->join('gudang_stock_documents as d', 'd.id', '=', 'm.document_id')
            ->join('gudang_locations as l', 'l.id', '=', 'm.location_id')
            ->whereIn('l.warehouse_id', $access->warehouses())
            ->where('m.item_id', $item)
            ->when(isset($filters['location_id']), fn ($query) => $query->where('m.location_id', $filters['location_id']))
            ->orderByDesc('m.id')
            ->paginate(50, ['m.id', 'm.quantity_milli', 'm.document_id', 'd.serial', 'd.public_id', 'd.type', 'd.source', DB::raw('coalesce(m.occurred_at, d.posted_at) as posted_at'), 'l.name as location_name']);

        $movements->through(function ($movement) {
            $movement->posted_at = Carbon::parse($movement->posted_at, 'UTC')->toISOString();

            return $movement;
        });

        return $this->successResponse($movements);
    }
}
