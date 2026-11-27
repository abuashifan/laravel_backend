<?php

namespace App\Modules\Gudang\Controllers;

use App\Modules\Gudang\PosBridge;
use App\Modules\Gudang\WarehouseAccess;
use App\Modules\Gudang\WarehouseDocuments;
use App\Shared\Permission\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DocumentController extends GudangController
{
    public function index(Request $request, WarehouseAccess $access, PermissionService $permissions)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'open' => 'sometimes|boolean', 'id' => 'sometimes|integer|min:1']);
        $types = array_keys(array_filter(WarehouseDocuments::PERMISSION, fn ($permission) => $permissions->can($permission)));
        if ($permissions->can('gudang.receipts.create')) {
            $types[] = 'issue';
        }
        if ($permissions->can('gudang.issues.create')) {
            $types[] = 'receipt';
        }
        if ($permissions->can('gudang.stock.approve')) {
            $types = array_keys(WarehouseDocuments::PREFIX);
        }
        $db = DB::connection('gudang');
        $docs = $db->table('gudang_stock_documents as d')
            ->leftJoin('gudang_locations as l', 'l.id', '=', 'd.location_id')
            ->leftJoin('gudang_locations as t', 't.id', '=', 'd.target_id')
            ->leftJoin('gudang_stock_documents as parent', 'parent.id', '=', 'd.parent_id')
            ->leftJoin('gudang_stock_documents as original', 'original.id', '=', 'd.reverses_id')
            ->leftJoin('gudang_stock_documents as correction', 'correction.reverses_id', '=', 'd.id')
            ->whereIn('d.type', $types)
            ->where(function ($q) use ($access, $db) {
                $q->whereIn('d.location_id', $access->locations())->orWhereIn('d.target_id', $access->locations())
                    ->orWhere(function ($multi) use ($access, $db) {
                        $multi->whereNull('d.location_id')
                            ->whereIn('d.id', $db->table('gudang_stock_movements')->select('document_id')->whereIn('location_id', $access->locations()))
                            ->whereNotIn('d.id', $db->table('gudang_stock_movements')->select('document_id')->whereNotIn('location_id', $access->locations()));
                    });
            })
            ->when(! empty($data['open']), fn ($q) => $q->whereIn('d.status', ['draft', 'ordered', 'partial', 'transit', 'disputed', 'pending']))
            ->when(isset($data['id']), fn ($q) => $q->where('d.id', $data['id']))
            ->orderByDesc('d.id')->paginate(30, ['d.id', 'd.serial', 'd.type', 'd.public_id', 'd.status', 'd.source', 'parent.type as parent_type', 'parent.serial as parent_serial', 'original.type as reverses_type', 'original.serial as reverses_serial', 'correction.serial as reversal_serial', 'd.location_id', 'd.target_id', 'd.document_date', 'l.name as origin', 't.name as destination']);
        $lines = $db->table('gudang_doc_lines as x')->join('gudang_items as i', 'i.id', '=', 'x.item_id')
            ->whereIn('x.document_id', $docs->pluck('id'))->orderBy('x.id')
            ->get(['x.document_id', 'x.item_id', 'x.quantity_milli', 'x.received_milli', 'x.input_unit', 'x.input_quantity_milli', 'x.factor_milli', 'x.batch_id', 'i.batch_enabled', 'i.name', 'i.unit', 'i.sku'])->groupBy('document_id');
        $legacyIds = $docs->pluck('id')->diff($lines->keys());
        $receipts = $db->table('gudang_stock_movements as m')->join('gudang_items as i', 'i.id', '=', 'm.item_id')
            ->join('gudang_locations as l', 'l.id', '=', 'm.location_id')
            ->leftJoin('gudang_movement_batches as batch', 'batch.movement_id', '=', 'm.id')
            ->whereIn('m.document_id', $legacyIds)->whereIn('m.location_id', $access->locations())->orderBy('m.id')
            ->get(['m.document_id', 'm.item_id', 'm.quantity_milli', 'm.quantity_milli as received_milli', 'm.input_unit', 'm.input_quantity_milli', 'm.factor_milli', 'batch.batch_id', 'i.batch_enabled', 'i.name', 'i.unit', 'i.sku', 'l.name as location_name'])
            ->groupBy('document_id');
        $resolutions = $db->table('gudang_actions')->whereIn('document_id', $docs->pluck('id'))
            ->whereIn('action', ['resolve', 'resolve_return', 'resolve_loss', 'resolve_return_extra', 'resolve_loss_extra'])->pluck('action', 'document_id');
        $visibleLocations = $access->locations()->pluck('id')->flip();
        $movementLocations = $db->table('gudang_stock_movements')->whereIn('document_id', $docs->pluck('id'))
            ->distinct()->get(['document_id', 'location_id'])->groupBy('document_id');
        $batchDocuments = $db->table('gudang_movement_batches as batch')->join('gudang_stock_movements as movement', 'movement.id', '=', 'batch.movement_id')
            ->whereIn('movement.document_id', $docs->pluck('id'))->groupBy('movement.document_id', 'movement.item_id', 'movement.location_id', 'batch.batch_id')
            ->havingRaw('sum(batch.quantity_milli) <> 0')->pluck('movement.document_id');
        $effectiveDocuments = $db->table('gudang_stock_movements')->whereIn('document_id', $docs->pluck('id'))
            ->groupBy('document_id', 'item_id', 'location_id')->havingRaw('sum(quantity_milli) <> 0')->pluck('document_id')->merge($batchDocuments)->flip();
        $reversalNotes = $db->table('gudang_actions')->whereIn('document_id', $docs->pluck('id'))->where('action', 'reverse')->pluck('note', 'document_id');
        $posStatuses = app(PosBridge::class)->statusByDocument($docs->pluck('id')->all());
        $docs->through(function ($doc) use ($lines, $receipts, $resolutions, $visibleLocations, $movementLocations, $effectiveDocuments, $reversalNotes, $permissions, $posStatuses) {
            $allLocations = $movementLocations->get($doc->id, collect());
            $canAccessMovements = $allLocations->isNotEmpty() && $allLocations->every(fn ($row) => $visibleLocations->has($row->location_id));
            $doc->access_origin = $doc->location_id === null ? $canAccessMovements : $visibleLocations->has($doc->location_id);
            $doc->access_target = $visibleLocations->has($doc->target_id ?? 0);
            $doc->resolution = match ($resolutions->get($doc->id)) {
                'resolve_loss','resolve_loss_extra' => 'loss', 'resolve_return','resolve_return_extra','resolve' => 'return', default => null,
            };
            $doc->surplus_accepted = str_ends_with((string) $resolutions->get($doc->id), '_extra');
            $doc->number = WarehouseDocuments::PREFIX[$doc->type].'-'.$doc->serial;
            $doc->parent_number = $doc->parent_type ? WarehouseDocuments::PREFIX[$doc->parent_type].'-'.$doc->parent_serial : null;
            $doc->reverses_number = $doc->reverses_type ? WarehouseDocuments::PREFIX[$doc->reverses_type].'-'.$doc->reverses_serial : null;
            $doc->reversal_number = $doc->reversal_serial !== null ? 'KOR-'.$doc->reversal_serial : null;
            $doc->reversal_note = $reversalNotes->get($doc->id);
            $doc->can_reverse = $permissions->can('gudang.stock.approve') && $canAccessMovements && $effectiveDocuments->has($doc->id) && $doc->type !== 'reversal'
                && in_array($doc->status, ['posted', 'approved', 'received', 'resolved'], true);
            $doc->pos_status = $posStatuses[$doc->id] ?? null;
            $doc->lines = $lines->get($doc->id, $receipts->get($doc->id, []));

            return $doc;
        });

        return $this->successResponse($docs);
    }

    public function store(Request $request, WarehouseDocuments $documents)
    {
        $input = $request->validate([
            'type' => ['required', Rule::in(array_diff(array_keys(WarehouseDocuments::PREFIX), ['reversal']))],
            'request_key' => 'required|string|max:80', 'source' => 'required|string|max:160',
            'location_id' => 'required|integer|exists:gudang.gudang_locations,id',
            'target_id' => 'nullable|integer|different:location_id|exists:gudang.gudang_locations,id',
            'document_date' => 'required|date_format:Y-m-d', 'lines' => 'required|array|list|min:1|max:100',
            'parent_id' => 'nullable|integer|exists:gudang.gudang_stock_documents,id',
            'lines.*.item_id' => 'required|integer|distinct|exists:gudang.gudang_items,id',
            'lines.*.quantity' => ['required', 'numeric', 'regex:/^-?\d{1,8}(\.\d{1,3})?$/D'],
            'lines.*.unit_id' => 'nullable|integer|exists:gudang.gudang_item_units,id',
            'lines.*.batch_id' => 'nullable|integer|exists:gudang.gudang_batches,id',
        ]);

        return $this->saved($documents->draft($input, $this->user($request)->id));
    }

    public function act(int $id, Request $request, WarehouseDocuments $documents)
    {
        $input = $request->validate([
            'action' => 'required|in:cancel,post,ship,submit,approve,reject,order,receive,resolve',
            'request_key' => 'required|string|max:80', 'note' => 'nullable|string|max:500', 'resolution' => 'sometimes|in:return,loss', 'surplus' => 'sometimes|in:return,accept',
            'lines' => 'sometimes|array|list|max:100', 'lines.*.item_id' => 'required|integer|distinct',
            'lines.*.batch_id' => 'nullable|integer|exists:gudang.gudang_batches,id',
            'lines.*.quantity' => ['required', 'numeric', 'regex:/^\d{1,8}(\.\d{1,3})?$/D'],
        ]);

        return $this->saved($documents->act($id, $input, $this->user($request)->id));
    }

    public function reverse(int $id, Request $request, WarehouseDocuments $documents)
    {
        $input = $request->validate([
            'request_key' => 'required|string|max:72',
            'note' => 'required|string|max:500',
        ]);

        return $this->saved($documents->reverse($id, $input, $this->user($request)->id));
    }

    private function saved(object $document)
    {
        return $this->successResponse([
            'id' => $document->id, 'public_id' => $document->public_id, 'type' => $document->type,
            'number' => WarehouseDocuments::PREFIX[$document->type].'-'.$document->serial, 'status' => $document->status,
        ]);
    }
}
