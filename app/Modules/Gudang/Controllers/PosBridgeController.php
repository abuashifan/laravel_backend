<?php

namespace App\Modules\Gudang\Controllers;

use App\Modules\Gudang\PosBridge;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Jembatan Gudang -> Kasir (Fase 4a). */
class PosBridgeController extends GudangController
{
    public function __construct(private readonly PosBridge $bridge) {}

    public function show()
    {
        return $this->successResponse($this->bridge->state());
    }

    public function update(Request $request)
    {
        $input = $request->validate(['enabled' => 'required|boolean']);

        return $this->successResponse($this->bridge->setEnabled($this->user($request)->id, (bool) $input['enabled']));
    }

    public function dispatches(Request $request)
    {
        $input = $request->validate(['status' => ['sometimes', Rule::in(PosBridge::STATUSES)]]);

        return $this->successResponse($this->bridge->list($input['status'] ?? null));
    }

    public function send(int $id, Request $request)
    {
        return $this->successResponse($this->bridge->send($id, $this->user($request)->id), 'Surat jalan dikirim ke Kasir.');
    }

    public function retry(int $id, Request $request)
    {
        $input = $request->validate(['import_missing' => 'sometimes|boolean']);

        return $this->successResponse($this->bridge->retry($id, (bool) ($input['import_missing'] ?? false)));
    }

    public function acknowledge(int $id, Request $request)
    {
        $input = $request->validate(['note' => 'required|string|max:500']);

        return $this->successResponse($this->bridge->acknowledge($id, $this->user($request)->id, $input['note']));
    }

    public function importProducts()
    {
        return $this->successResponse($this->bridge->importProducts());
    }
}
