<?php

namespace App\Modules\Gudang\Controllers;

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
        $data = $request->validate(['page'=>'sometimes|integer|min:1','open'=>'sometimes|boolean','id'=>'sometimes|integer|min:1']);
        $types = array_keys(array_filter(WarehouseDocuments::PERMISSION, fn ($permission) => $permissions->can($permission)));
        if ($permissions->can('gudang.stock.approve')) {
            array_push($types, 'transfer','adjustment');
        }
        $db = DB::connection('gudang');
        $docs = $db->table('gudang_stock_documents as d')
            ->join('gudang_locations as l','l.id','=','d.location_id')
            ->leftJoin('gudang_locations as t','t.id','=','d.target_id')
            ->whereIn('d.type', $types)
            ->where(function ($q) use ($access) {
                $q->whereIn('d.location_id', $access->locations())->orWhereIn('d.target_id', $access->locations());
            })
            ->when(!empty($data['open']), fn ($q) => $q->whereIn('d.status', ['draft','ordered','partial','transit','disputed','pending']))
            ->when(isset($data['id']), fn ($q) => $q->where('d.id',$data['id']))
            ->orderByDesc('d.id')->paginate(30, ['d.id','d.type','d.public_id','d.status','d.source','d.location_id','d.target_id','d.document_date','l.name as origin','t.name as destination']);
        $lines = $db->table('gudang_doc_lines as x')->join('gudang_items as i','i.id','=','x.item_id')
            ->whereIn('x.document_id',$docs->pluck('id'))->orderBy('x.id')
            ->get(['x.document_id','x.item_id','x.quantity_milli','x.received_milli','i.name','i.unit','i.sku'])->groupBy('document_id');
        $docs->through(function ($doc) use ($lines) {
            $doc->number = WarehouseDocuments::PREFIX[$doc->type].'-'.$doc->id;
            $doc->lines = $lines->get($doc->id, []);
            return $doc;
        });
        return $this->successResponse($docs);
    }

    public function store(Request $request, WarehouseDocuments $documents)
    {
        $input = $request->validate([
            'type'=>['required',Rule::in(array_keys(WarehouseDocuments::PREFIX))],
            'request_key'=>'required|string|max:80','source'=>'required|string|max:160',
            'location_id'=>'required|integer|exists:gudang.gudang_locations,id',
            'target_id'=>'nullable|integer|different:location_id|exists:gudang.gudang_locations,id',
            'document_date'=>'required|date_format:Y-m-d','lines'=>'required|array|list|min:1|max:100',
            'lines.*.item_id'=>'required|integer|distinct|exists:gudang.gudang_items,id',
            'lines.*.quantity'=>['required','numeric','regex:/^-?\d{1,8}(\.\d{1,3})?$/D'],
        ]);
        return $this->successResponse($documents->draft($input,$this->user($request)->id));
    }

    public function act(int $id, Request $request, WarehouseDocuments $documents)
    {
        $input = $request->validate([
            'action'=>'required|in:cancel,post,ship,submit,approve,reject,order,receive,resolve',
            'request_key'=>'required|string|max:80','note'=>'nullable|string|max:500',
            'lines'=>'sometimes|array|list|max:100','lines.*.item_id'=>'required|integer|distinct',
            'lines.*.quantity'=>['required','numeric','regex:/^\d{1,8}(\.\d{1,3})?$/D'],
        ]);
        return $this->successResponse($documents->act($id,$input,$this->user($request)->id));
    }
}
