<?php

namespace App\Modules\Gudang\Controllers;

use App\Modules\Gudang\StockLedger;
use App\Modules\Gudang\StockUnits;
use App\Modules\Gudang\WarehouseAccess;
use App\Modules\Gudang\WarehouseDocuments;
use App\Shared\Permission\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockControlController extends GudangController
{
    private function authorizeAny(PermissionService $permissions, array $keys): void
    {
        foreach ($keys as $key) {
            if ($permissions->can('gudang.'.$key)) {
                return;
            }
        }
        abort(403);
    }

    public function units(Request $request, PermissionService $permissions, ?int $id = null)
    {
        $db = DB::connection('gudang');
        if ($request->isMethod('get')) {
            $this->authorizeAny($permissions, ['stock.view', 'items.view', 'items.create', 'items.edit', 'receipts.create', 'issues.create', 'transfers.create', 'stock.adjust', 'stock.approve']);
            $input = $request->validate(['item_id' => 'nullable|integer|min:1']);

            return $this->successResponse($db->table('gudang_item_units')->when($input['item_id'] ?? null, fn ($q, $item) => $q->where('item_id', $item))->orderBy('item_id')->orderBy('name')->get());
        }
        $this->authorizeAny($permissions, $id === null ? ['items.create', 'items.edit'] : ['items.edit']);
        $input = $request->validate([
            'item_id' => $id ? 'sometimes|integer|min:1' : 'required|integer|min:1',
            'name' => 'required|string|max:24',
            'factor' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,8}(\.\d{1,3})?$/D'],
        ]);
        $result = $db->transaction(function () use ($db, $input, $id) {
            $existing = $id ? $db->table('gudang_item_units')->where('id', $id)->firstOrFail() : null;
            $itemId = $existing?->item_id ?? $input['item_id'];
            if ($existing && isset($input['item_id']) && (int) $input['item_id'] !== (int) $itemId) {
                throw ValidationException::withMessages(['item_id' => 'Barang satuan tidak dapat diubah.']);
            }

            return app(StockUnits::class)->save((int) $itemId, $input['name'], (string) $input['factor'], $id);
        }, 3);

        return $this->successResponse($result, 'Satuan tersimpan.', $id ? 200 : 201);
    }

    public function batches(Request $request, PermissionService $permissions, WarehouseAccess $access, ?int $id = null)
    {
        $db = DB::connection('gudang');
        if ($request->isMethod('get')) {
            $this->authorizeAny($permissions, ['stock.view', 'items.view', 'items.create', 'items.edit', 'receipts.create', 'issues.create', 'transfers.create', 'stock.adjust', 'stock.approve']);
            $input = $request->validate(['item_id' => 'nullable|integer|min:1']);

            return $this->successResponse($db->table('gudang_batches')->when($input['item_id'] ?? null, fn ($q, $item) => $q->where('item_id', $item))->orderBy('item_id')->orderBy('code')->get());
        }
        if ($id !== null) {
            $this->authorizeAny($permissions, ['stock.approve']);
            $input = $request->validate(['status' => 'required|in:active,quarantine', 'note' => 'required|string|max:500']);
            $note = trim($input['note']);
            if ($note === '') {
                throw ValidationException::withMessages(['note' => 'Alasan wajib diisi.']);
            }
            $batch = $db->transaction(function () use ($db, $access, $id, $input, $note, $request) {
                $reference = $db->table('gudang_batches')->where('id', $id)->firstOrFail();
                $db->table('gudang_items')->where('id', $reference->item_id)->lockForUpdate()->firstOrFail();
                $batch = $db->table('gudang_batches')->where('id', $id)->lockForUpdate()->firstOrFail();
                $locations = $db->table('gudang_batch_balances')->where('batch_id', $id)->where('quantity_milli', '<>', 0)->pluck('location_id')->all();
                $access->requireLocations($locations);
                $db->table('gudang_batches')->where('id', $id)->update(['status' => $input['status']]);
                $db->table('gudang_batch_actions')->insert(['batch_id' => $id, 'user_id' => $request->user()->id, 'status' => $input['status'], 'note' => $note, 'created_at' => now()]);
                $batch->status = $input['status'];

                return $batch;
            }, 3);

            return $this->successResponse($batch);
        }
        $this->authorizeAny($permissions, ['items.create', 'items.edit', 'receipts.create']);
        $input = $request->validate(['item_id' => 'required|integer|min:1', 'code' => 'required|string|max:100', 'expires_at' => 'nullable|date_format:Y-m-d']);
        $batch = $db->transaction(function () use ($db, $input) {
            $item = $db->table('gudang_items')->where('id', $input['item_id'])->lockForUpdate()->firstOrFail();
            if (! $item->batch_enabled) {
                throw ValidationException::withMessages(['item_id' => 'Barang tidak menggunakan batch.']);
            }
            $code = trim($input['code']);
            if ($code === '' || $db->table('gudang_batches')->where('item_id', $item->id)->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['code' => 'Kode batch kosong atau sudah digunakan.']);
            }
            $id = $db->table('gudang_batches')->insertGetId(['item_id' => $item->id, 'code' => $code, 'expires_at' => $input['expires_at'] ?? null, 'status' => 'active']);

            return $db->table('gudang_batches')->where('id', $id)->first();
        }, 3);

        return $this->successResponse($batch, 'Batch tersimpan.', 201);
    }

    public function batchStock(Request $request, PermissionService $permissions, WarehouseAccess $access)
    {
        $this->authorizeAny($permissions, ['stock.view']);
        $input = $request->validate(['item_id' => 'nullable|integer|min:1', 'location_id' => 'nullable|integer|min:1']);
        $db = DB::connection('gudang');
        $rows = $db->table('gudang_batch_balances as b')->join('gudang_batches as lot', 'lot.id', '=', 'b.batch_id')
            ->join('gudang_locations as l', 'l.id', '=', 'b.location_id')->join('gudang_warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->whereIn('b.location_id', $access->locations())->when($input['item_id'] ?? null, fn ($q, $item) => $q->where('lot.item_id', $item))
            ->when($input['location_id'] ?? null, fn ($q, $location) => $q->where('b.location_id', $location))
            ->orderByRaw('CASE WHEN lot.expires_at IS NULL THEN 1 ELSE 0 END')->orderBy('lot.expires_at')->orderBy('lot.id')
            ->get(['lot.id as batch_id', 'lot.item_id', 'lot.code', 'lot.expires_at', 'lot.status', 'b.quantity_milli', 'l.id as location_id', 'l.name as location_name', 'l.kind as location_kind', 'w.id as warehouse_id', 'w.name as warehouse_name']);

        return $this->successResponse($rows);
    }

    public function stockRules(Request $request, PermissionService $permissions, WarehouseAccess $access)
    {
        $db = DB::connection('gudang');
        if ($request->isMethod('get')) {
            $this->authorizeAny($permissions, ['stock.view', 'settings.view', 'settings.edit']);
            $input = $request->validate(['item_id' => 'nullable|integer|min:1', 'warehouse_id' => 'nullable|integer|min:1']);
            $query = $db->table('gudang_stock_rules as r')->join('gudang_items as i', 'i.id', '=', 'r.item_id')->join('gudang_warehouses as w', 'w.id', '=', 'r.warehouse_id')
                ->whereIn('r.warehouse_id', $access->warehouses())->when($input['item_id'] ?? null, fn ($q, $item) => $q->where('r.item_id', $item))
                ->when($input['warehouse_id'] ?? null, fn ($q, $warehouse) => $q->where('r.warehouse_id', $warehouse));

            return $this->successResponse($query->orderBy('i.sku')->orderBy('w.name')->get(['r.*', 'i.sku', 'i.name as item_name', 'w.name as warehouse_name']));
        }
        $this->authorizeAny($permissions, ['settings.edit']);
        $decimal = ['required', 'numeric', 'min:0', 'regex:/^\d{1,8}(\.\d{1,3})?$/D'];
        $input = $request->validate(['item_id' => 'required|integer|min:1', 'warehouse_id' => 'required|integer|min:1', 'minimum' => $decimal, 'maximum' => $decimal, 'reorder' => $decimal]);
        abort_unless($access->warehouses()->where('id', $input['warehouse_id'])->exists(), 403);
        abort_unless($db->table('gudang_items')->where('id', $input['item_id'])->exists(), 404);
        $minimum = StockLedger::milli($input['minimum']);
        $maximum = StockLedger::milli($input['maximum']);
        $reorder = StockLedger::milli($input['reorder']);
        if ($maximum > 0 && ($minimum > $maximum || $reorder > $maximum)) {
            throw ValidationException::withMessages(['maximum' => 'Maksimum harus setidaknya sebesar minimum dan titik pemesanan.']);
        }
        $key = ['item_id' => $input['item_id'], 'warehouse_id' => $input['warehouse_id']];
        $db->table('gudang_stock_rules')->upsert([$key + ['minimum_milli' => $minimum, 'max_milli' => $maximum, 'reorder_milli' => $reorder]], ['item_id', 'warehouse_id'], ['minimum_milli', 'max_milli', 'reorder_milli']);

        return $this->successResponse($db->table('gudang_stock_rules')->where($key)->first());
    }

    public function trace(Request $request, int $id, PermissionService $permissions, WarehouseAccess $access)
    {
        $this->authorizeAny($permissions, ['stock.view']);
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $db = DB::connection('gudang');
        $batch = $db->table('gudang_batches')->where('id', $id)->firstOrFail();
        $movements = $db->table('gudang_movement_batches as mb')->join('gudang_stock_movements as m', 'm.id', '=', 'mb.movement_id')
            ->join('gudang_stock_documents as d', 'd.id', '=', 'm.document_id')->join('gudang_locations as l', 'l.id', '=', 'm.location_id')
            ->leftJoin('gudang_stock_documents as parent', 'parent.id', '=', 'd.parent_id')->leftJoin('gudang_stock_documents as original', 'original.id', '=', 'd.reverses_id')
            ->where('mb.batch_id', $id)->whereIn('m.location_id', $access->locations())->orderByDesc('m.id')
            ->paginate(100, ['d.id as document_id', 'd.public_id', 'd.serial', 'd.type', 'd.source', 'd.status', 'd.document_date', 'l.name as location_name', 'mb.quantity_milli', 'm.occurred_at', 'parent.public_id as parent_public_id', 'original.public_id as reverses_public_id']);
        $movements->through(function ($movement) {
            $movement->number = (WarehouseDocuments::PREFIX[$movement->type] ?? $movement->type).'-'.$movement->serial;

            return $movement;
        });

        return $this->successResponse(['batch' => $batch, 'movements' => $movements->items(), 'current_page' => $movements->currentPage(), 'last_page' => $movements->lastPage(), 'total' => $movements->total()]);
    }
}
