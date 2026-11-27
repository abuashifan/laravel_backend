<?php

namespace App\Modules\Gudang\Controllers;

use App\Modules\Gudang\WarehouseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PutawayController extends GudangController
{
    public function suggest(Request $request, WarehouseAccess $access): JsonResponse
    {
        $input = $request->validate([
            'from' => 'required|integer|min:1',
            'items' => 'required|array|list|min:1|max:100',
            'items.*' => 'required|integer|distinct|exists:gudang.gudang_items,id',
        ]);
        $access->requireLocations([$input['from']]);
        $db = DB::connection('gudang');
        $origin = $db->table('gudang_locations')->where('id', $input['from'])->firstOrFail();
        if (! in_array($origin->kind, ['terima', 'simpan'], true)) {
            return $this->successResponse([]);
        }

        $locations = $db->table('gudang_locations')->where('warehouse_id', $origin->warehouse_id)
            ->where('id', '<>', $origin->id)->where('kind', 'simpan')->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'sku_slots', 'preferred_category']);
        $balances = $db->table('gudang_stock_balances')->whereIn('location_id', $locations->pluck('id'))
            ->where('quantity_milli', '>', 0);
        $occupied = (clone $balances)->groupBy('location_id')->selectRaw('location_id, count(*) as used')->pluck('used', 'location_id');
        $same = $balances->whereIn('item_id', $input['items'])->groupBy('location_id')
            ->selectRaw('location_id, count(*) as matching')->pluck('matching', 'location_id');
        $categories = $db->table('gudang_items')->whereIn('id', $input['items'])->pluck('category');
        $suggestions = [];
        foreach ($locations as $location) {
            $sameCount = (int) ($same[$location->id] ?? 0);
            $newCount = count($input['items']) - $sameCount;
            $free = $location->sku_slots === null ? null : max(0, (int) $location->sku_slots - (int) ($occupied[$location->id] ?? 0));
            if ($free !== null && $free < $newCount) {
                continue;
            }
            $preferred = mb_strtolower(trim((string) $location->preferred_category));
            $categoryCount = $preferred === '' ? 0 : $categories->filter(fn ($category) => mb_strtolower(trim((string) $category)) === $preferred)->count();
            $suggestions[] = [
                'id' => $location->id, 'name' => $location->name, 'same_items' => $sameCount,
                'matching_category' => $categoryCount, 'free_slots' => $free,
            ];
        }
        usort($suggestions, fn ($a, $b) => [$b['same_items'], $b['matching_category'], $b['free_slots'] ?? -1] <=> [$a['same_items'], $a['matching_category'], $a['free_slots'] ?? -1]);

        return $this->successResponse(array_slice($suggestions, 0, 5));
    }

    public function settings(int $id, Request $request, WarehouseAccess $access): JsonResponse
    {
        $access->requireLocations([$id]);
        $input = $request->validate([
            'sku_slots' => 'present|nullable|integer|min:1|max:10000',
            'preferred_category' => 'present|nullable|string|max:100',
        ]);
        $location = DB::connection('gudang')->table('gudang_locations')->where('id', $id)->where('kind', 'simpan');
        abort_unless($location->exists(), 422, 'Aturan penyimpanan hanya untuk lokasi simpan.');
        $location->update($input);

        return $this->successResponse($location->firstOrFail());
    }
}
