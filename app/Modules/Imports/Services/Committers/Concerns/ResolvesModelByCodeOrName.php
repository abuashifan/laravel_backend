<?php

namespace App\Modules\Imports\Services\Committers\Concerns;

use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Models\Product;
use Illuminate\Support\Str;

/**
 * Lookup model master data berdasarkan kode atau nama — dibutuhkan
 * committer transaksi (Fase 3+) karena CSV memakai nama/kode, bukan ID.
 *
 * ContactService dan ProductService tidak punya method findByCode/Name,
 * dan menambahkannya memperbesar permukaan service yang sudah besar
 * untuk kebutuhan satu pemanggil. Query model langsung — persis seperti
 * yang dilakukan BusinessReferenceValidator dan HandlesSalesDocuments
 * di Sales module.
 */
trait ResolvesModelByCodeOrName
{
    /**
     * Cari kontak berdasarkan kode atau nama.
     *
     * Urutan: exact match contact_code → case-insensitive contact_code
     * → case-insensitive name. Hanya mengembalikan kontak aktif.
     */
    protected function findContactByCodeOrName(string $value): ?Contact
    {
        if ($value === '') {
            return null;
        }

        $normalized = trim($value);

        // 1. Exact match on code
        $contact = Contact::query()
            ->where('contact_code', $normalized)
            ->where('is_active', true)
            ->first();

        if ($contact instanceof Contact) {
            return $contact;
        }

        // 2. Case-insensitive code
        $contact = Contact::query()
            ->whereRaw('LOWER(contact_code) = ?', [mb_strtolower($normalized)])
            ->where('is_active', true)
            ->first();

        if ($contact instanceof Contact) {
            return $contact;
        }

        // 3. Case-insensitive name
        return Contact::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($normalized)])
            ->where('is_active', true)
            ->first();
    }

    /**
     * Cari produk berdasarkan kode atau nama.
     *
     * Urutan: exact match product_code → case-insensitive product_code
     * → case-insensitive product_name. Hanya mengembalikan produk aktif.
     */
    protected function findProductByCodeOrName(string $value): ?Product
    {
        if ($value === '') {
            return null;
        }

        $normalized = trim($value);

        // 1. Exact match on code
        $product = Product::query()
            ->where('product_code', $normalized)
            ->where('is_active', true)
            ->first();

        if ($product instanceof Product) {
            return $product;
        }

        // 2. Case-insensitive code
        $product = Product::query()
            ->whereRaw('LOWER(product_code) = ?', [mb_strtolower($normalized)])
            ->where('is_active', true)
            ->first();

        if ($product instanceof Product) {
            return $product;
        }

        // 3. Case-insensitive name
        return Product::query()
            ->whereRaw('LOWER(product_name) = ?', [mb_strtolower($normalized)])
            ->where('is_active', true)
            ->first();
    }
}
