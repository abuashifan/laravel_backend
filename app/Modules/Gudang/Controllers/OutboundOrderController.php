<?php

namespace App\Modules\Gudang\Controllers;

use App\Modules\Gudang\OutboundOrders;
use App\Modules\Gudang\WarehouseAccess;
use App\Modules\Gudang\WarehouseDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OutboundOrderController extends GudangController
{
    public function index(Request $request, WarehouseAccess $access): JsonResponse
    {
        $filters = $request->validate(['page' => 'sometimes|integer|min:1', 'open' => 'sometimes|boolean']);
        $db = DB::connection('gudang');
        $orders = $db->table('gudang_outbound_orders as o')
            ->join('gudang_locations as l', 'l.id', '=', 'o.location_id')
            ->join('gudang_warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->whereIn('o.location_id', $access->locations())
            ->when($filters['open'] ?? true, fn ($query) => $query->whereIn('o.status', ['reserved', 'partial']))
            ->orderByDesc('o.id')
            ->paginate(20, ['o.id', 'o.destination', 'o.status', 'l.name as location_name', 'w.name as warehouse_name']);
        $ids = $orders->getCollection()->pluck('id');
        $lines = $db->table('gudang_outbound_lines as line')
            ->join('gudang_items as item', 'item.id', '=', 'line.item_id')
            ->whereIn('line.order_id', $ids)->orderBy('item.sku')
            ->get(['line.*', 'item.sku', 'item.name'])->groupBy('order_id');
        $actions = $db->table('gudang_outbound_actions as a')
            ->leftJoin('gudang_stock_documents as d', 'd.id', '=', 'a.document_id')
            ->leftJoin('gudang_stock_documents as correction', 'correction.reverses_id', '=', 'd.id')
            ->whereIn('a.order_id', $ids)->orderBy('a.id')
            ->get(['a.id', 'a.order_id', 'a.document_id', 'a.action', 'a.note', 'd.serial', 'correction.serial as reversal_serial'])
            ->groupBy('order_id');
        $orders->through(function ($order) use ($lines, $actions) {
            $order->lines = $lines->get($order->id, []);
            $order->actions = collect($actions->get($order->id, []))->map(fn ($action) => [
                'id' => $action->id, 'document_id' => $action->document_id, 'action' => $action->action, 'note' => $action->note,
                'number' => $action->serial === null ? null : WarehouseDocuments::PREFIX['issue'].'-'.$action->serial,
                'reversal_number' => $action->reversal_serial === null ? null : WarehouseDocuments::PREFIX['reversal'].'-'.$action->reversal_serial,
            ]);

            return $order;
        });

        return $this->successResponse($orders);
    }

    public function store(Request $request, OutboundOrders $orders): JsonResponse
    {
        $input = $request->validate([
            'request_key' => 'required|string|max:80',
            'location_id' => 'required|integer',
            'destination' => 'required|string|max:160',
            'lines' => 'required|array|list|min:1|max:100',
            'lines.*.item_id' => 'required|integer|distinct|exists:gudang.gudang_items,id',
            'lines.*.quantity' => $this->quantityRules(),
        ]);
        $order = $orders->reserve($input, $this->user($request)->id);

        return $this->successResponse(['id' => $order->id, 'status' => $order->status], 'Pesanan tersimpan.');
    }

    public function act(int $id, Request $request, OutboundOrders $orders): JsonResponse
    {
        $input = $request->validate([
            'request_key' => 'required|string|max:80',
            'action' => 'required|in:ship,cancel',
            'note' => 'required_if:action,cancel|nullable|string|max:500',
            'lines' => 'required_if:action,ship|prohibited_if:action,cancel|array|list|min:1|max:100',
            'lines.*.item_id' => 'required|integer|distinct',
            'lines.*.quantity' => $this->quantityRules(),
        ]);
        $order = $orders->act($id, $input, $this->user($request)->id);

        return $this->successResponse(['id' => $order->id, 'status' => $order->status]);
    }

    private function quantityRules(): array
    {
        return ['required', 'numeric', 'gt:0', 'regex:/^\d{1,8}(\.\d{1,3})?$/D'];
    }
}
