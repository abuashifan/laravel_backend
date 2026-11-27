<?php

namespace App\Shared\Warehouse;

use App\Shared\Models\Company;

/**
 * Kontrak jembatan Gudang -> Accounting (Fase 4b). Gudang mengirim kuantitas (dan harga
 * beli bila ada); Accounting yang menilai persediaan dengan rata-rata dan membuat jurnal.
 *
 * Penerimaan Gudang menjadi Goods Receipt Accounting yang sudah diterima, sehingga tagihan
 * vendor dibuat "Dari Goods Receipt" dan stok tidak tercatat dua kali. Penyesuaian dan
 * selisih opname menjadi penyesuaian stok Accounting. Semua kiriman idempoten menurut id
 * dokumen Gudang.
 */
interface InventoryAccountingGateway
{
    /** False bila perusahaan belum memakai Finlite Accounting. */
    public function available(Company $company): bool;

    /**
     * Pilihan untuk pengaturan jembatan: gudang dan pemasok aktif di Accounting.
     *
     * @return array{warehouses: list<array{id:int, code:?string, name:string}>, vendors: list<array{id:int, name:string}>}|null
     */
    public function options(Company $company): ?array;

    /**
     * Produk persediaan aktif di Accounting yang kode produknya sama dengan SKU Gudang.
     *
     * @param  list<string>  $skus
     * @return array<string, array{product_id:int, name:string}> kunci = SKU; SKU tanpa pasangan tidak muncul
     */
    public function match(Company $company, array $skus): array;

    /**
     * Catat penerimaan sebagai Goods Receipt yang langsung diterima. Pemasok dicocokkan
     * dengan nama asal barang; bila tidak ketemu memakai pemasok bawaan.
     *
     * @param  array{source_id:int, number:string, date:string, supplier:string, vendor_id:int, warehouse_id:int,
     *   lines: list<array{product_id:int, description:string, quantity:float, unit_cost:?float}>}  $receipt
     * @return array{reference:string}
     */
    public function postReceipt(Company $company, array $receipt): array;

    /**
     * Catat penyesuaian stok (tambah dan/atau kurang) dari penyesuaian atau selisih opname.
     *
     * @param  array{source_id:int, number:string, date:string, warehouse_id:int, description:string,
     *   lines: list<array{product_id:int, quantity:float}>}  $adjustment  quantity bertanda: positif menambah stok
     * @return array{reference:string}
     */
    public function postAdjustment(Company $company, array $adjustment): array;
}
