<?php

namespace App\Modules\Gudang\Controllers;

use App\Modules\Gudang\AccountingBridge;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Jembatan Gudang -> Accounting (Fase 4b). */
class AccountingBridgeController extends GudangController
{
    public function __construct(private readonly AccountingBridge $bridge) {}

    public function show()
    {
        return $this->successResponse($this->bridge->state());
    }

    public function options()
    {
        return $this->successResponse($this->bridge->options());
    }

    public function update(Request $request)
    {
        $input = $request->validate([
            'enabled' => 'required|boolean',
            'warehouse_id' => 'sometimes|nullable|integer|min:1',
            'vendor_id' => 'sometimes|nullable|integer|min:1',
            'start_date' => 'sometimes|nullable|date_format:Y-m-d',
        ]);

        return $this->successResponse($this->bridge->save($this->user($request)->id, $input));
    }

    public function dispatches(Request $request)
    {
        $input = $request->validate(['status' => ['sometimes', Rule::in(AccountingBridge::STATUSES)]]);

        return $this->successResponse($this->bridge->list($input['status'] ?? null));
    }

    public function sync()
    {
        return $this->successResponse($this->bridge->sync());
    }

    public function retry(int $id)
    {
        return $this->successResponse($this->bridge->retry($id));
    }

    public function costs(int $id, Request $request)
    {
        $input = $request->validate([
            'lines' => 'required|array|min:1|max:100',
            'lines.*.item_id' => 'required|integer|distinct',
            'lines.*.unit_cost' => 'required|numeric|min:0|max:999999999999',
        ]);

        return $this->successResponse($this->bridge->setCosts($id, collect($input['lines'])->pluck('unit_cost', 'item_id')->all()));
    }
}
