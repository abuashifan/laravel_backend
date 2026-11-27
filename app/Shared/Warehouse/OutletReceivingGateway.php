<?php

namespace App\Shared\Warehouse;

use App\Shared\Models\Company;

/**
 * Kontrak jembatan Gudang -> Kasir (Fase 4a). Gudang tidak menyentuh tenant
 * Kasir secara langsung: kiriman barang ke toko lewat kontrak ini, dan modul
 * Kasir yang menentukan kapan stok toko bertambah.
 *
 * Aturan pemilik angka (dokumen 10 bagian 9): Gudang memiliki stok gudang,
 * Kasir memiliki stok toko. Kiriman hanya mengubah stok Kasir saat petugas
 * toko mengonfirmasi jumlah yang benar-benar diterima; selisih dilaporkan
 * kembali ke Gudang dan tidak mengubah stok pihak mana pun diam-diam.
 */
interface OutletReceivingGateway
{
    /** False bila perusahaan belum memakai Finlite Kasir. */
    public function available(Company $company): bool;

    /**
     * Cocokkan barang Gudang dengan produk Kasir lewat barcode, lalu SKU.
     *
     * @param  list<array{sku:string, barcodes:list<string>}>  $items
     * @return array<string, array{product_id:int, name:string}> kunci = SKU Gudang; barang tanpa pasangan tidak muncul
     */
    public function match(Company $company, array $items): array;

    /**
     * Buat produk Kasir untuk barang Gudang yang belum ada (sekali jalan).
     * Produk baru berstok terlacak, harga jual 0 sampai pemilik mengisinya.
     *
     * @param  list<array{sku:string, barcode:?string, name:string, unit:string}>  $items
     * @return array{created:int, skipped:int}
     */
    public function importProducts(Company $company, array $items): array;

    /**
     * Antarkan satu kiriman ke kotak masuk Kasir. Idempoten menurut `key`:
     * kiriman yang sama tidak pernah tercatat dua kali.
     *
     * @param  array{key:string, number:string, from:string, shipped_at:string, lines:list<array{product_id:int, sku:string, name:string, unit:string, quantity:string}>}  $shipment
     */
    public function deliver(Company $company, array $shipment): bool;

    /**
     * Keadaan kiriman di Kasir menurut kunci: pending, received, atau discrepancy,
     * beserta jumlah diterima per baris. Kunci yang belum ada tidak muncul.
     *
     * @param  list<string>  $keys
     * @return array<string, array{status:string, received_at:?string, received_by:?string, note:?string, lines:list<array{sku:string, expected:string, actual:?string}>}>
     */
    public function statuses(Company $company, array $keys): array;
}
