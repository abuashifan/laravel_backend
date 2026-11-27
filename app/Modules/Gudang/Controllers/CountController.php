<?php

namespace App\Modules\Gudang\Controllers;

use App\Modules\Gudang\AccountingBridge;
use App\Modules\Gudang\StockCounts;
use Illuminate\Http\Request;

class CountController extends GudangController
{
    public function index(Request $request, StockCounts $counts)
    {
        $request->validate(['page' => 'sometimes|integer|min:1']);

        return $this->successResponse($counts->listing());
    }

    public function store(Request $request, StockCounts $counts)
    {
        $input = $request->validate([
            'request_key' => 'required|string|max:80', 'location_ids' => 'required|array|list|min:1|max:100',
            'location_ids.*' => 'required|integer|distinct', 'mode' => 'required|in:full,cycle',
            'item_ids' => 'sometimes|array|list|min:1', 'abc_class' => 'sometimes|in:A,B,C', 'item_ids.*' => 'required|integer|distinct|exists:gudang.gudang_items,id',
        ]);

        return $this->successResponse($counts->create($input, $this->user($request)->id));
    }

    public function due(StockCounts $counts)
    {
        return $this->successResponse($counts->due());
    }

    public function show(int $id, StockCounts $counts)
    {
        return $this->successResponse($counts->show($id));
    }

    public function lines(int $id, Request $request, StockCounts $counts)
    {
        $input = $request->validate([
            'request_key' => 'required|string|max:80', 'lines' => 'required|array|list|min:1|max:500',
            'lines.*.id' => 'required|integer|distinct', 'lines.*.quantity' => ['required', 'regex:/^\d{1,8}(\.\d{1,3})?$/D'],
            'lines.*.recount' => 'sometimes|boolean',
        ]);

        return $this->successResponse($counts->record($id, $input, $this->user($request)->id));
    }

    public function act(int $id, Request $request, StockCounts $counts)
    {
        $input = $request->validate(['request_key' => 'required|string|max:80', 'action' => 'required|in:submit,approve,reject,cancel', 'note' => 'nullable|string|max:500']);

        $count = $counts->act($id, $input, $this->user($request)->id);
        // Selisih opname yang disetujui menjadi penyesuaian stok di Accounting bila jembatan aktif.
        app(AccountingBridge::class)->syncQuietly();

        return $this->successResponse($count);
    }
}
