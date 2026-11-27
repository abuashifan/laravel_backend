<?php

namespace App\Modules\Gudang\Controllers;

use App\Modules\Gudang\BarcodeLabelPdf;
use App\Modules\Gudang\LabelBarcode;
use App\Modules\Gudang\OutboundOrders;
use App\Modules\Gudang\WarehouseAccess;
use App\Modules\Gudang\WarehouseDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        $lines = $db->table('gudang_outbound_lines as line')->join('gudang_items as item', 'item.id', '=', 'line.item_id')
            ->whereIn('line.order_id', $ids)->orderBy('item.sku')->get(['line.*', 'item.sku', 'item.name'])->groupBy('order_id');
        $allocations = $db->table('gudang_outbound_allocations as a')
            ->join('gudang_items as i', 'i.id', '=', 'a.item_id')
            ->join('gudang_locations as l', 'l.id', '=', 'a.location_id')
            ->leftJoin('gudang_batches as b', 'b.id', '=', 'a.batch_id')
            ->whereIn('a.order_id', $ids)->orderBy('l.name')->orderBy('l.id')->orderBy('i.sku')->orderBy('a.id')
            ->get(['a.*', 'l.name as location_name', 'i.sku', 'i.name', 'i.unit', 'b.code as batch_code'])->groupBy('order_id');
        $runs = $db->table('gudang_outbound_runs')->whereIn('order_id', $ids)->orderByDesc('id')->get();
        $runIds = $runs->pluck('id');
        $pickRows = $db->table('gudang_outbound_picks')->whereIn('run_id', $runIds)
            ->get(['run_id', 'allocation_id', 'planned_milli', 'picked_milli', 'reason'])->groupBy('run_id');
        $packages = $db->table('gudang_outbound_packages')->whereIn('run_id', $runIds)->orderBy('number')->get()->groupBy('run_id');
        $shipments = $db->table('gudang_outbound_ship_docs as s')
            ->join('gudang_stock_documents as d', 'd.id', '=', 's.document_id')
            ->leftJoin('gudang_stock_documents as correction', 'correction.reverses_id', '=', 'd.id')
            ->whereIn('s.run_id', $runIds)->orderBy('d.id')
            ->get(['s.run_id', 's.document_id', 'd.serial', 'correction.serial as reversal_serial'])->groupBy('run_id');
        foreach ($runs as $run) {
            $run->picks = $pickRows->get($run->id, []);
            $run->packages = $packages->get($run->id, []);
            $run->documents = collect($shipments->get($run->id, []))->map(fn ($doc) => $this->document($doc));
        }
        $runsByOrder = $runs->groupBy('order_id');
        $actions = $db->table('gudang_outbound_actions as a')
            ->leftJoin('gudang_stock_documents as d', 'd.id', '=', 'a.document_id')
            ->leftJoin('gudang_stock_documents as correction', 'correction.reverses_id', '=', 'd.id')
            ->whereIn('a.order_id', $ids)->whereIn('a.action', ['cancel', 'reset', 'ship'])->where(function ($query) {
                $query->whereIn('a.action', ['cancel', 'reset'])->orWhereNotNull('a.document_id');
            })->orderBy('a.id')
            ->get(['a.id', 'a.order_id', 'a.run_id', 'a.document_id', 'a.action', 'a.note', 'd.serial', 'correction.serial as reversal_serial'])
            ->groupBy('order_id');
        $orders->through(function ($order) use ($lines, $allocations, $runsByOrder, $actions) {
            $order->lines = $lines->get($order->id, []);
            $order->allocations = $allocations->get($order->id, []);
            $order->runs = $runsByOrder->get($order->id, []);
            $order->actions = collect($actions->get($order->id, []))->map(fn ($action) => [
                'id' => $action->id, 'run_id' => $action->run_id, 'action' => $action->action, 'note' => $action->note,
            ] + $this->document($action));

            return $order;
        });

        return $this->successResponse($orders);
    }

    public function store(Request $request, OutboundOrders $orders): JsonResponse
    {
        $input = $request->validate([
            'request_key' => 'required|string|max:80',
            'location_id' => 'required_without:warehouse_id|prohibits:warehouse_id|integer|min:1',
            'warehouse_id' => 'required_without:location_id|prohibits:location_id|integer|min:1',
            'destination' => 'required|string|max:160',
            'lines' => 'required|array|list|min:1|max:100',
            'lines.*' => 'required|array:item_id,quantity,unit_id',
            'lines.*.item_id' => 'required|integer|distinct|exists:gudang.gudang_items,id',
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,8}(\.\d{1,3})?$/D'],
            'lines.*.unit_id' => 'nullable|integer|min:1',
        ]);
        $order = $orders->reserve($input, $this->user($request)->id);

        return $this->successResponse(['id' => $order->id, 'status' => $order->status], 'Pesanan tersimpan.');
    }

    public function act(int $id, Request $request, OutboundOrders $orders): JsonResponse
    {
        $input = $request->validate([
            'request_key' => 'required|string|max:80',
            'action' => 'required|in:pick,pack,ship,reset,cancel',
            'run_id' => 'required_if:action,pack,ship,reset|prohibited_if:action,pick,cancel|integer|min:1',
            'note' => 'required_if:action,cancel,reset|prohibited_if:action,pick,pack,ship|nullable|string|max:500',
            'lines' => 'required_if:action,pick|prohibited_unless:action,pick|array|list|min:1|max:200',
            'lines.*' => 'required|array:allocation_id,quantity,location_code,item_code,batch_code,reason',
            'lines.*.allocation_id' => 'required|integer|distinct|min:1',
            'lines.*.quantity' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,8}(\.\d{1,3})?$/D'],
            'lines.*.location_code' => 'nullable|string|max:100',
            'lines.*.item_code' => 'nullable|string|max:100',
            'lines.*.batch_code' => 'nullable|string|max:100',
            'lines.*.reason' => 'nullable|string|max:300',
            'packages' => 'required_if:action,pack|prohibited_unless:action,pack|array|list|min:1|max:50',
            'packages.*' => 'required|array:weight_grams,note',
            'packages.*.weight_grams' => 'required|integer|min:1|max:1000000',
            'packages.*.note' => 'nullable|string|max:300',
        ]);
        $order = $orders->act($id, $input, $this->user($request)->id);

        return $this->successResponse(['id' => $order->id, 'status' => $order->status]);
    }

    public function labels(int $id, int $run, WarehouseAccess $access, LabelBarcode $barcode, BarcodeLabelPdf $pdf): Response
    {
        $db = DB::connection('gudang');
        $order = $db->table('gudang_outbound_orders as o')->join('gudang_outbound_runs as r', 'r.order_id', '=', 'o.id')
            ->where('o.id', $id)->where('r.id', $run)->whereIn('r.status', ['packed', 'shipped'])
            ->whereIn('o.location_id', $access->locations())->firstOrFail(['o.destination']);
        $packages = $db->table('gudang_outbound_packages')->where('run_id', $run)->orderBy('number')->get();
        abort_if($packages->isEmpty(), 404);
        $labels = $packages->map(function ($package) use ($id, $run, $order, $barcode) {
            $code = "PK-{$id}-{$run}-{$package->number}";

            return [
                'code' => $code, 'svg' => $barcode->svg($code),
                'name' => "Kemasan {$package->number} / {$package->weight_grams} g",
                'details' => mb_str_split('Tujuan: '.$order->destination, 42),
            ];
        })->all();

        return response($pdf->render($labels, 100, 60), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="label-kirim-'.$id.'-'.$run.'.pdf"',
        ]);
    }

    private function document(object $doc): array
    {
        return [
            'document_id' => $doc->document_id,
            'number' => $doc->serial === null ? null : WarehouseDocuments::PREFIX['issue'].'-'.$doc->serial,
            'reversal_number' => $doc->reversal_serial === null ? null : WarehouseDocuments::PREFIX['reversal'].'-'.$doc->reversal_serial,
        ];
    }
}
