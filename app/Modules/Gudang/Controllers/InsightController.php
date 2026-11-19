<?php

namespace App\Modules\Gudang\Controllers;

use App\Modules\Gudang\BarcodeLabelPdf;
use App\Modules\Gudang\LabelBarcode;
use App\Modules\Gudang\StockInsights;
use App\Modules\Gudang\WarehouseAccess;
use App\Shared\Export\ExcelExportService;
use App\Shared\Permission\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InsightController extends GudangController
{
    public function show(Request $request, StockInsights $insights, WarehouseAccess $access, PermissionService $permissions, ExcelExportService $excel)
    {
        abort_unless($permissions->can('gudang.stock.view'), 403);
        $options = $request->validate([
            'kind' => ['required', Rule::in(['abc', 'replenishment', 'expiry', 'aging', 'counts'])],
            'warehouse_id' => 'nullable|integer|min:1',
            'q' => 'nullable|string|max:100',
            'page' => 'sometimes|integer|min:1',
            'days' => 'sometimes|integer|min:0|max:3650',
            'export' => 'sometimes|boolean',
            'summary' => 'sometimes|boolean',
        ]);

        if ($request->boolean('summary')) {
            abort_unless($options['kind'] === 'replenishment', 422);
            $rows = $insights->report($options + ['all' => true], $access);

            return $this->successResponse(['count' => $rows->where('reorder_needed', true)->count(), 'calculated_at' => now('Asia/Jakarta')->toIso8601String()]);
        }

        if ($request->boolean('export')) {
            abort_unless($permissions->can('gudang.stock.export'), 403);
            $rows = $insights->report($options + ['all' => true], $access);
            $columns = $rows->isEmpty() ? ['sku', 'name', 'unit'] : array_keys((array) $rows->first());
            $labels = ['sku' => 'SKU', 'name' => 'Barang', 'unit' => 'Satuan', 'warehouse' => 'Gudang',
                'quantity_milli' => 'Stok', 'outgoing_milli' => 'Keluar 90 hari', 'suggested_milli' => 'Saran pembelian',
                'batch_code' => 'Batch', 'expires_at' => 'Kedaluwarsa', 'variance_milli' => 'Selisih',
                'counted_milli' => 'Hasil hitung', 'recount_milli' => 'Hitung ulang', 'calculated_at' => 'Diperbarui',
                'measured_lines' => 'Baris dihitung per lokasi', 'accurate_lines' => 'Baris sesuai per lokasi',
                'location_accuracy_percent' => 'Akurasi lokasi (%)'];
            $values = $rows->map(fn ($row) => array_map(function ($column) use ($row) {
                $value = $row->$column;
                if ($value !== null && str_ends_with($column, '_milli')) {
                    return (int) $value / 1000;
                }
                if (is_bool($value)) {
                    return (int) $value;
                }

                return is_string($value) && preg_match('/^[=+@-]/', $value) ? "'".$value : $value;
            }, $columns))->all();

            return $excel->downloadFromArray($values, array_map(fn ($column) => $labels[$column] ?? str_replace('_', ' ', $column), $columns), 'kendali-'.$options['kind']);
        }

        return $this->successResponse($insights->report($options, $access));
    }

    public function labels(Request $request, WarehouseAccess $access, PermissionService $permissions, LabelBarcode $barcode, BarcodeLabelPdf $pdf)
    {
        abort_unless($permissions->can('gudang.stock.export'), 403);
        $options = $request->validate([
            'items' => 'sometimes|array|max:200', 'items.*' => 'integer|min:1|distinct',
            'locations' => 'sometimes|array|max:200', 'locations.*' => 'integer|min:1|distinct',
            'width' => 'sometimes|integer|min:30|max:150', 'height' => 'sometimes|integer|min:20|max:100',
        ]);
        $itemIds = $options['items'] ?? [];
        $locationIds = $options['locations'] ?? [];
        $access->requireLocations($locationIds);
        // Item master data is tenant-wide; a user needs access to at least one warehouse.
        abort_unless($access->warehouses()->exists(), 403);
        $db = DB::connection('gudang');
        $items = $db->table('gudang_items')->whereIn('id', $itemIds)->get(['id', 'sku', 'name']);
        abort_unless($items->count() === count($itemIds), 404);
        $codes = $db->table('gudang_barcodes')->whereIn('item_id', $itemIds)->orderBy('code')->get()->groupBy('item_id');
        $labels = [];
        foreach ($items as $item) {
            $code = $codes->get($item->id)?->first()?->code ?? $item->sku;
            $labels[] = ['name' => $item->name, 'code' => $code, 'svg' => $barcode->svg($code)];
        }
        foreach ($db->table('gudang_locations')->whereIn('id', $locationIds)->get(['id', 'name', 'barcode']) as $location) {
            $code = $location->barcode ?: 'LOC-'.$location->id;
            $labels[] = ['name' => $location->name, 'code' => $code, 'svg' => $barcode->svg($code)];
        }
        abort_if(count($labels) === 0, 422, 'Pilih barang atau lokasi untuk dicetak.');

        return response($pdf->render($labels, $options['width'] ?? 60, $options['height'] ?? 35), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="label-gudang.pdf"',
        ]);
    }
}
