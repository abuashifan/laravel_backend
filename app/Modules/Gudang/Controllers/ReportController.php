<?php

namespace App\Modules\Gudang\Controllers;

use App\Modules\Gudang\WarehouseAccess;
use App\Shared\Export\ExcelExportService;
use App\Shared\Permission\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController extends GudangController
{
    public function show(Request $request, WarehouseAccess $access, PermissionService $permissions, ExcelExportService $excel)
    {
        $options = $request->validate([
            'kind' => ['required', Rule::in(['stock', 'warehouse', 'movements', 'low', 'audit'])],
            'warehouse_id' => 'nullable|integer', 'location_id' => 'nullable|integer', 'q' => 'nullable|string|max:100',
            'export' => 'sometimes|boolean', 'page' => 'sometimes|integer|min:1',
        ]);
        $kind = $options['kind'];
        abort_unless($permissions->can($kind === 'audit' ? 'gudang.audit.view' : 'gudang.stock.view'), 403);
        if ($request->boolean('export')) {
            abort_unless($permissions->can('gudang.stock.export'), 403);
        }
        $db = DB::connection('gudang');
        $locations = $access->locations()
            ->when($options['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($options['location_id'] ?? null, fn ($q, $id) => $q->where('id', $id));
        $balance = $db->table('gudang_stock_balances as b')->whereIn('b.location_id', clone $locations);
        if ($kind === 'audit') {
            $query = $db->table('gudang_actions as a')->join('gudang_stock_documents as d', 'd.id', '=', 'a.document_id')
                ->where(function ($q) use ($locations, $db) {
                    $q->whereIn('d.location_id', clone $locations)->orWhereIn('d.target_id', clone $locations)
                        ->orWhere(function ($multi) use ($locations, $db) {
                            $multi->whereNull('d.location_id')->whereIn('d.id', $db->table('gudang_stock_movements')->select('document_id')->whereIn('location_id', clone $locations))
                                ->whereNotIn('d.id', $db->table('gudang_stock_movements')->select('document_id')->whereNotIn('location_id', clone $locations));
                        });
                })->orderByDesc('a.id')->select('d.id as dokumen', 'd.type as jenis', 'a.action as aksi', 'a.user_id as pengguna', 'a.created_at as waktu', 'a.note as alasan');
        } elseif ($kind === 'low') {
            $totals = $balance->groupBy('b.item_id')->selectRaw('b.item_id, sum(b.quantity_milli) as total');
            $query = $db->table('gudang_items as i')->leftJoinSub($totals, 's', 's.item_id', '=', 'i.id')
                ->whereExists($locations)->where('i.minimum_milli', '>', 0)->whereRaw('COALESCE(s.total,0) < i.minimum_milli')
                ->selectRaw('i.sku, i.name as barang, COALESCE(s.total,0)/1000.0 as jumlah, i.minimum_milli/1000.0 as minimum, i.unit as satuan')->orderBy('i.sku');
        } else {
            $movement = $kind === 'movements';
            $query = $movement ? $db->table('gudang_stock_movements as b')->whereIn('b.location_id', clone $locations) : $balance;
            $query->join('gudang_items as i', 'i.id', '=', 'b.item_id')->join('gudang_locations as l', 'l.id', '=', 'b.location_id')
                ->join('gudang_warehouses as w', 'w.id', '=', 'l.warehouse_id');
            if ($kind === 'warehouse') {
                $query->selectRaw('w.name as gudang,i.sku,i.name as barang,sum(b.quantity_milli)/1000.0 as jumlah,i.unit as satuan')
                    ->groupBy('w.name', 'i.sku', 'i.name', 'i.unit')->orderBy('w.name')->orderBy('i.sku');
            } else {
                $query->selectRaw('w.name as gudang,l.name as lokasi,l.kind as kondisi,i.sku,i.name as barang,b.quantity_milli/1000.0 as jumlah,i.unit as satuan');
                if ($movement) {
                    $query->join('gudang_stock_documents as d', 'd.id', '=', 'b.document_id')
                        ->addSelect('d.id as dokumen', 'd.type as jenis', DB::raw('coalesce(b.occurred_at,d.posted_at) as waktu'))->orderByDesc('b.id');
                } else {
                    $query->orderBy('i.sku')->orderBy('l.id');
                }
            }
        }
        if ($kind !== 'audit' && ($search = trim($options['q'] ?? '')) !== '') {
            $query->where(function ($q) use ($search, $db) {
                $q->whereLike('i.sku', '%'.$search.'%')->orWhereLike('i.name', '%'.$search.'%')
                    ->orWhereIn('i.id', $db->table('gudang_barcodes')->select('item_id')->where('code', mb_strtoupper($search)));
            });
        }
        if ($request->boolean('export')) {
            $columns = match ($kind) {
                'audit' => ['dokumen', 'jenis', 'aksi', 'pengguna', 'waktu', 'alasan'],
                'low' => ['sku', 'barang', 'jumlah', 'minimum', 'satuan'],
                'warehouse' => ['gudang', 'sku', 'barang', 'jumlah', 'satuan'],
                'movements' => ['gudang', 'lokasi', 'kondisi', 'sku', 'barang', 'jumlah', 'satuan', 'dokumen', 'jenis', 'waktu'],
                default => ['gudang', 'lokasi', 'kondisi', 'sku', 'barang', 'jumlah', 'satuan'],
            };
            $rows = $query->get()->map(fn ($row) => array_map(function ($key) use ($row) {
                $value = $row->$key;
                if (in_array($key, ['jumlah', 'minimum'], true)) {
                    return (float) $value;
                }

                return is_string($value) && str_starts_with($value, '=') ? "'".$value : $value;
            }, $columns))->all();

            return $excel->downloadFromArray($rows,$columns,'gudang-'.$kind);
        }

        return $this->successResponse($query->paginate(50));
    }
}
