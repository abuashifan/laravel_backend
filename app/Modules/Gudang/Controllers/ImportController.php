<?php

namespace App\Modules\Gudang\Controllers;

use App\Modules\Gudang\ItemCatalog;
use App\Modules\Gudang\StockLedger;
use App\Modules\Gudang\WarehouseAccess;
use App\Modules\Imports\Services\SpreadsheetReaderFactory;
use App\Shared\Export\ExcelExportService;
use App\Shared\Permission\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ImportController extends GudangController
{
    private const COLUMNS = ['SKU','Nama','Satuan','Kategori','Barcode','Minimum','Saldo awal','Tanggal'];

    public function template(ExcelExportService $excel)
    {
        return $excel->downloadTemplate(['headers'=>self::COLUMNS,'rows'=>[],'filename'=>'templat-barang-gudang']);
    }

    public function upload(Request $request, SpreadsheetReaderFactory $factory, ItemCatalog $catalog, StockLedger $ledger, WarehouseAccess $access, PermissionService $permissions)
    {
        $input = $request->validate(['file'=>'required|file|max:4096|extensions:xlsx,csv','commit'=>'required|boolean','location_id'=>'required|integer']);
        $location = (int) $input['location_id'];
        $access->requireLocations([$location]);
        $file = $input['file'];
        $reader = $factory->make(strtolower($file->getClientOriginalExtension()));
        $path = $file->getRealPath();
        if (array_diff(['SKU','Nama','Satuan'],$reader->headers($path))) {
            throw ValidationException::withMessages(['file'=>'Kolom wajib: SKU, Nama, Satuan. Gunakan templat.']);
        }
        $signature = hash_file('sha256',$path);
        $db = DB::connection('gudang');
        $result = [];
        $db->beginTransaction();
        try {
            foreach ($reader->rows($path) as $row => $values) {
                abort_if(count($result) >= 5000,422,'Maksimum 5.000 baris per berkas.');
                try {
                    $action = $db->transaction(function () use ($db,$catalog,$ledger,$permissions,$row,$values,$signature,$location) {
                        $sku = mb_strtoupper(trim((string) ($values['SKU'] ?? '')));
                        $code = mb_strtoupper(trim((string) ($values['Barcode'] ?? '')));
                        $id = $db->table('gudang_items')->where('sku',$sku)->value('id');
                        $id ??= $code !== '' ? $db->table('gudang_barcodes')->where('code',$code)->value('item_id') : null;
                        if ($id && ! $permissions->can('gudang.items.edit')) {
                            throw ValidationException::withMessages(['sku'=>'Izin mengubah barang diperlukan.']);
                        }
                        $data = ['sku'=>$sku,'name'=>$values['Nama'] ?? '', 'unit'=>$values['Satuan'] ?? ''];
                        if ($code !== '') {
                            $codes = $id ? $db->table('gudang_barcodes')->where('item_id',$id)->pluck('code')->all() : [];
                            $data['barcodes'] = array_values(array_unique([...$codes,$code]));
                        }
                        foreach (['Kategori'=>'category','Minimum'=>'minimum'] as $column=>$key) {
                            if (isset($values[$column]) && $values[$column] !== '') $data[$key] = $values[$column];
                        }
                        $item = $catalog->save($data,$id);
                        $quantity = trim((string) ($values['Saldo awal'] ?? ''));
                        if ($quantity !== '' && $quantity !== '0') {
                            if (! $permissions->can('gudang.receipts.create')) {
                                throw ValidationException::withMessages(['quantity'=>'Izin penerimaan diperlukan untuk saldo awal.']);
                            }
                            $opening = Validator::make(['quantity'=>$quantity,'date'=>$values['Tanggal'] ?? ''],[
                                'quantity'=>['required','numeric','gt:0','regex:/^\d{1,8}(\.\d{1,3})?$/D'],
                                'date'=>'required|date_format:Y-m-d',
                            ])->validate();
                            $ledger->receive([
                                'request_key'=>hash('sha256',"opening/$signature/$row/$location"),
                                'source'=>'Saldo awal impor','document_date'=>$opening['date'],
                                'lines'=>[['item_id'=>$item['id'],'location_id'=>$location,'quantity'=>$opening['quantity']]],
                            ],auth()->id());
                        }
                        return $id ? 'Diperbarui' : 'Baru';
                    });
                    $result[] = ['row'=>$row,'sku'=>$values['SKU'] ?? '', 'result'=>$action];
                } catch (ValidationException $error) {
                    $result[] = ['row'=>$row,'sku'=>$values['SKU'] ?? '', 'result'=>'Gagal','error'=>collect($error->errors())->flatten()->first()];
                }
            }
            if ($request->boolean('commit')) $db->commit();
            else $db->rollBack();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
        return $this->successResponse($result);
    }
}
