<?php

namespace App\Modules\Gudang\Controllers;

use App\Modules\Gudang\WarehouseAccess;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Plan;
use App\Shared\Subscription\SubscriptionService;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarehouseController extends GudangController
{
    public function create(Request $request, WarehouseAccess $access, TenantContext $tenant, SubscriptionService $subscriptions)
    {
        abort_unless($access->owner(),403);
        $name = $request->validate(['name'=>'required|string|max:100|unique:gudang.gudang_warehouses,name'])['name'];
        $plan = $subscriptions->currentFor($tenant->company()->creator, 'gudang')?->plan;
        abort_if($plan?->code === Plan::GUDANG_FREE_CODE,422,'Paket gratis dibatasi satu gudang.');
        $db = DB::connection('gudang');
        $id = $db->transaction(function () use ($db,$name) {
            abort_unless($db->table('gudang_warehouses')->insertOrIgnore(['name'=>$name]),422,'Nama gudang sudah digunakan.');
            $id = $db->table('gudang_warehouses')->where('name',$name)->value('id');
            $db->table('gudang_locations')->insert(['warehouse_id'=>$id,'name'=>'Umum']);
            return $id;
        });
        return $this->successResponse(['id'=>$id,'name'=>$name],'Gudang tersimpan.',201);
    }

    public function members(WarehouseAccess $access, TenantContext $tenant)
    {
        abort_unless($access->owner(),403);
        $assignments = DB::connection('gudang')->table('gudang_members')->get()->groupBy('user_id');
        $members = CompanyUser::query()->where('company_id',$tenant->companyId())->where('status','active')
            ->where('user_id','<>',$tenant->company()->created_by)->with('user:id,name')->get()
            ->map(fn ($member) => ['id'=>$member->user_id,'name'=>$member->user->name,'warehouses'=>$assignments->get($member->user_id,collect())->pluck('warehouse_id')]);
        return $this->successResponse($members);
    }

    public function assign(Request $request, int $id, WarehouseAccess $access, TenantContext $tenant)
    {
        abort_unless($access->owner(),403);
        $ids = $request->validate(['warehouses'=>'present|array','warehouses.*'=>'integer|distinct|exists:gudang.gudang_warehouses,id'])['warehouses'];
        abort_unless(CompanyUser::query()->where('company_id',$tenant->companyId())->where('user_id',$id)->where('status','active')->exists(),404);
        $db = DB::connection('gudang');
        $db->transaction(function () use ($db,$ids,$id) {
            $db->table('gudang_warehouses')->orderBy('id')->lockForUpdate()->firstOrFail();
            $db->table('gudang_members')->where('user_id',$id)->delete();
            foreach ($ids as $warehouse) {
                $db->table('gudang_members')->insert(['user_id'=>$id,'warehouse_id'=>$warehouse]);
            }
        });
        return $this->successResponse($ids);
    }

    public function parties()
    {
        return $this->successResponse(DB::connection('gudang')->table('gudang_parties')->orderBy('name')->get());
    }

    public function party(Request $request)
    {
        $data = $request->validate(['kind'=>'required|in:pemasok,tujuan','name'=>'required|string|max:160']);
        $parties = DB::connection('gudang')->table('gudang_parties');
        $parties->insertOrIgnore($data);
        return $this->successResponse($parties->where($data)->firstOrFail());
    }

    public function settings()
    {
        return $this->successResponse(['allow_negative'=>DB::connection('gudang')->table('gudang_settings')->where('key','allow_negative')->value('value') === '1']);
    }

    public function setSettings(Request $request)
    {
        $request->validate(['allow_negative'=>'required|boolean']);
        DB::connection('gudang')->table('gudang_settings')->upsert([['key'=>'allow_negative','value'=>$request->boolean('allow_negative')?'1':'0']],['key'],['value']);
        return $this->settings();
    }

    public function photo(int $id)
    {
        return $this->successResponse(DB::connection('gudang')->table('gudang_item_photos')->where('item_id',$id)->value('data_url'));
    }

    public function setPhoto(int $id, Request $request)
    {
        $file = $request->validate(['photo'=>'required|file|mimes:jpeg,png,webp|max:300'])['photo'];
        $db = DB::connection('gudang');
        abort_unless($db->table('gudang_items')->where('id',$id)->exists(),404);
        $url = 'data:'.$file->getMimeType().';base64,'.base64_encode($file->get());
        $db->table('gudang_item_photos')->upsert([['item_id'=>$id,'data_url'=>$url]],['item_id'],['data_url']);
        return $this->successResponse(true);
    }
}
