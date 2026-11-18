<?php

namespace App\Modules\Gudang\Controllers;

use App\Modules\Gudang\StockLedger;
use App\Shared\Subscription\PlanOwnerResolver;
use App\Shared\Subscription\SubscriptionService;
use App\Shared\Tenant\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends GudangController
{
    public function items(): JsonResponse
    {
        return $this->successResponse(DB::connection('gudang')->table('gudang_items')->orderBy('name')->orderBy('id')->get());
    }

    public function createItem(Request $request, TenantContext $tenant, PlanOwnerResolver $owners, SubscriptionService $subscriptions): JsonResponse
    {
        $item = $request->validate([
            'sku' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:160'],
            'unit' => ['required', 'string', 'max:24'],
        ]);
        $item['sku'] = mb_strtoupper($item['sku']);
        $owner = $owners->ownerOf($tenant->company());
        $limit = $subscriptions->summaryFor($owner, 'gudang')['limits']['products'];
        $db = DB::connection('gudang');
        $id = $db->transaction(function () use ($db, $item, $limit) {
            // Kunci stabil per tenant untuk kuota, termasuk ketika katalog masih kosong.
            $db->table('gudang_warehouses')->orderBy('id')->lockForUpdate()->firstOrFail();
            $items = $db->table('gudang_items');
            if ($limit !== null && $items->count() >= $limit) {
                throw ValidationException::withMessages(['sku' => 'Batas barang paket Gudang sudah tercapai.']);
            }
            if (! $items->insertOrIgnore($item)) {
                throw ValidationException::withMessages(['sku' => 'SKU sudah digunakan.']);
            }

            return $items->where('sku', $item['sku'])->value('id');
        }, 3);

        return $this->successResponse(['id' => $id] + $item, 'Barang tersimpan.', 201);
    }

    public function locations(): JsonResponse
    {
        $locations = DB::connection('gudang')->table('gudang_locations as l')
            ->join('gudang_warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->orderBy('w.name')->orderBy('l.name')->get(['l.*', 'w.name as warehouse_name']);

        return $this->successResponse($locations);
    }

    public function createLocation(Request $request): JsonResponse
    {
        $location = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:gudang.gudang_warehouses,id'],
            'name' => ['required', 'string', 'max:100'],
        ]);
        $locations = DB::connection('gudang')->table('gudang_locations');
        if (! $locations->insertOrIgnore($location)) {
            throw ValidationException::withMessages(['name' => 'Nama lokasi sudah digunakan di gudang ini.']);
        }

        return $this->successResponse($locations->where($location)->firstOrFail(), 'Lokasi tersimpan.', 201);
    }

    public function receive(Request $request, StockLedger $ledger): JsonResponse
    {
        $input = $request->validate([
            'request_key' => ['required', 'string', 'max:80'],
            'source' => ['required', 'string', 'max:160'],
            'lines' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'lines.*.item_id' => ['required', 'integer', 'exists:gudang.gudang_items,id'],
            'lines.*.location_id' => ['required', 'integer', 'exists:gudang.gudang_locations,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,8}(\.\d{1,3})?$/D'],
        ]);

        return $this->successResponse($ledger->receive($input, $this->user($request)->id), 'Penerimaan tercatat.');
    }

    public function balances(): JsonResponse
    {
        $balances = DB::connection('gudang')->table('gudang_stock_balances as b')
            ->join('gudang_items as i', 'i.id', '=', 'b.item_id')
            ->join('gudang_locations as l', 'l.id', '=', 'b.location_id')
            ->orderBy('i.name')->orderBy('l.name')
            ->get(['b.*', 'i.sku', 'i.name as item_name', 'i.unit', 'l.name as location_name']);

        return $this->successResponse($balances);
    }

    public function card(Request $request, int $item): JsonResponse
    {
        $db = DB::connection('gudang');
        abort_unless($db->table('gudang_items')->where('id', $item)->exists(), 404);
        $filters = $request->validate(['location_id' => ['sometimes', 'integer'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $movements = $db->table('gudang_stock_movements as m')
            ->join('gudang_stock_documents as d', 'd.id', '=', 'm.document_id')
            ->join('gudang_locations as l', 'l.id', '=', 'm.location_id')
            ->where('m.item_id', $item)
            ->when(isset($filters['location_id']), fn ($query) => $query->where('m.location_id', $filters['location_id']))
            ->orderByDesc('m.id')
            ->paginate(50, ['m.id', 'm.quantity_milli', 'm.document_id', 'd.public_id', 'd.type', 'd.source', 'd.posted_at', 'l.name as location_name']);

        $movements->through(function ($movement) {
            $movement->posted_at = Carbon::parse($movement->posted_at, 'UTC')->toISOString();

            return $movement;
        });

        return $this->successResponse($movements);
    }
}
