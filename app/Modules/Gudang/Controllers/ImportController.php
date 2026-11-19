<?php

namespace App\Modules\Gudang\Controllers;

use App\Modules\Gudang\ItemCatalog;
use App\Modules\Gudang\StockLedger;
use App\Modules\Gudang\StockUnits;
use App\Modules\Gudang\WarehouseAccess;
use App\Shared\Export\ExcelExportService;
use App\Shared\Export\TabularFileReader;
use App\Shared\Permission\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Reader\Exception;

class ImportController extends GudangController
{
    private const COLUMNS = ['SKU', 'Nama', 'Satuan', 'Kategori', 'Barcode', 'Minimum', 'Saldo awal', 'Tanggal', 'Satuan transaksi', 'Isi satuan', 'Lacak batch', 'Batch', 'Kedaluwarsa'];

    public function template(ExcelExportService $excel)
    {
        return $excel->downloadTemplate(['headers' => self::COLUMNS, 'fields' => ['sku', 'name', 'unit', 'category', 'barcode', 'minimum', 'opening', 'opening_date', 'input_unit', 'unit_factor', 'batch_enabled', 'batch', 'expires_at'], 'rows' => [], 'filename' => 'templat-barang-gudang']);
    }

    public function upload(Request $request, TabularFileReader $reader, ItemCatalog $catalog, StockLedger $ledger, WarehouseAccess $access, PermissionService $permissions)
    {
        $input = $request->validate(['file' => 'required|file|max:4096|extensions:xlsx,csv', 'commit' => 'required|boolean', 'location_id' => 'required|integer']);
        $location = (int) $input['location_id'];
        $access->requireLocations([$location]);
        $file = $input['file'];
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();
        try {
            $headers = $reader->fileHeaders($extension, $path);
        } catch (Exception|\RuntimeException $error) {
            throw ValidationException::withMessages(['file' => 'Berkas tidak dapat dibaca. Gunakan templat Excel atau CSV.']);
        }
        if (array_diff(['SKU', 'Nama', 'Satuan'], $headers)) {
            throw ValidationException::withMessages(['file' => 'Kolom wajib: SKU, Nama, Satuan. Gunakan templat.']);
        }
        $signature = hash_file('sha256', $path);
        $db = DB::connection('gudang');
        $result = [];
        $db->beginTransaction();
        try {
            foreach ($reader->fileRows($extension, $path) as $row => $values) {
                abort_if(count($result) >= 5000, 422, 'Maksimum 5.000 baris per berkas.');
                try {
                    $action = $db->transaction(function () use ($db, $catalog, $ledger, $permissions, $row, $values, $signature, $location) {
                        $sku = mb_strtoupper(trim((string) ($values['SKU'] ?? '')));
                        $code = mb_strtoupper(trim((string) ($values['Barcode'] ?? '')));
                        $id = $db->table('gudang_items')->where('sku', $sku)->value('id');
                        $id ??= $code !== '' ? $db->table('gudang_barcodes')->where('code', $code)->value('item_id') : null;
                        if ($id && ! $permissions->can('gudang.items.edit')) {
                            throw ValidationException::withMessages(['sku' => 'Izin mengubah barang diperlukan.']);
                        }
                        $existing = $id ? $db->table('gudang_items')->where('id', $id)->firstOrFail() : null;
                        $data = [
                            'sku' => $sku !== '' ? $sku : $existing?->sku,
                            'name' => ($values['Nama'] ?? '') !== '' ? $values['Nama'] : $existing?->name,
                            'unit' => ($values['Satuan'] ?? '') !== '' ? $values['Satuan'] : $existing?->unit,
                        ];
                        if ($code !== '') {
                            $codes = $id ? $db->table('gudang_barcodes')->where('item_id', $id)->pluck('code')->all() : [];
                            $data['barcodes'] = array_values(array_unique([...$codes, $code]));
                        }
                        foreach (['Kategori' => 'category', 'Minimum' => 'minimum'] as $column => $key) {
                            if (isset($values[$column]) && $values[$column] !== '') {
                                $data[$key] = $values[$column];
                            }
                        }
                        $item = $catalog->save($data, $id);
                        if (isset($values['Lacak batch']) && $values['Lacak batch'] !== '') {
                            $flag = Validator::make(['batch_enabled' => $values['Lacak batch']], ['batch_enabled' => 'required|boolean'])->validate();
                            $item = $catalog->save($data + $flag, $item['id']);
                        }
                        $unitId = null;
                        $inputUnit = trim((string) ($values['Satuan transaksi'] ?? ''));
                        if ($inputUnit !== '' && mb_strtolower($inputUnit) !== mb_strtolower($item['unit'])) {
                            $unitId = $db->table('gudang_item_units')->where('item_id', $item['id'])->where('name', $inputUnit)->value('id');
                            $factor = trim((string) ($values['Isi satuan'] ?? ''));
                            if ($unitId === null || $factor !== '') {
                                $unitId = app(StockUnits::class)->save($item['id'], $inputUnit, $factor, $unitId)->id;
                            }
                        }
                        $quantity = trim((string) ($values['Saldo awal'] ?? ''));
                        if ($quantity !== '' && $quantity !== '0') {
                            if (! $permissions->can('gudang.receipts.create')) {
                                throw ValidationException::withMessages(['quantity' => 'Izin penerimaan diperlukan untuk saldo awal.']);
                            }
                            $opening = Validator::make(['quantity' => $quantity, 'date' => $values['Tanggal'] ?? ''], [
                                'quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,8}(\.\d{1,3})?$/D'],
                                'date' => 'required|date_format:Y-m-d',
                            ])->validate();
                            $batchId = null;
                            if ($db->table('gudang_items')->where('id', $item['id'])->value('batch_enabled')) {
                                $lot = Validator::make(['code' => trim((string) ($values['Batch'] ?? '')), 'expires_at' => $values['Kedaluwarsa'] ?? null], [
                                    'code' => 'required|string|max:100', 'expires_at' => 'nullable|date_format:Y-m-d',
                                ])->validate();
                                $batch = $db->table('gudang_batches')->where('item_id', $item['id'])->where('code', $lot['code'])->first();
                                if ($batch !== null && isset($lot['expires_at']) && $batch->expires_at !== $lot['expires_at']) {
                                    throw ValidationException::withMessages(['Batch' => 'Tanggal kedaluwarsa batch berbeda dari data yang tersimpan.']);
                                }
                                $batchId = $batch?->id ?? $db->table('gudang_batches')->insertGetId(['item_id' => $item['id'], 'code' => $lot['code'], 'expires_at' => $lot['expires_at'] ?? null]);
                            }
                            $ledger->receive([
                                'request_key' => hash('sha256', "opening/$signature/$row/$location"),
                                'source' => 'Saldo awal impor', 'document_date' => $opening['date'],
                                'lines' => [['item_id' => $item['id'], 'location_id' => $location, 'quantity' => $opening['quantity'], 'unit_id' => $unitId, 'batch_id' => $batchId]],
                            ], auth()->id());
                        }

                        return $id ? 'Diperbarui' : 'Baru';
                    });
                    $result[] = ['row' => $row, 'sku' => $values['SKU'] ?? '', 'result' => $action];
                } catch (ValidationException $error) {
                    $result[] = ['row' => $row, 'sku' => $values['SKU'] ?? '', 'result' => 'Gagal', 'error' => collect($error->errors())->flatten()->first()];
                }
            }
            if ($request->boolean('commit')) {
                $db->commit();
            } else {
                $db->rollBack();
            }
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }

        return $this->successResponse($result);
    }
}
