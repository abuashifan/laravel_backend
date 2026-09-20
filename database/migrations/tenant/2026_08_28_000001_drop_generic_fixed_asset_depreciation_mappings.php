<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kunci mapping generik `fixed_assets.accumulated_depreciation` dan
     * `fixed_assets.depreciation_expense` dihapus dari
     * `config/account_mappings.php` karena sudah dipecah per kelas aset
     * (kendaraan/gedung/peralatan/perangkat lunak). Selama barisnya masih ada
     * di tabel tenant, halaman Pemetaan Akun tetap menampilkannya -- kini tanpa
     * label karena definisinya sudah tiada -- jadi field-nya ganda.
     *
     * Menghapusnya begitu saja bisa memutus posting: kategori aset yang kolom
     * akunnya masih null selama ini jatuh ke kunci generik itu. Fallback-nya
     * sekarang kunci kelas Peralatan, jadi yang dijaga di sini hanya satu hal:
     * kunci Peralatan tidak boleh kosong saat kunci generiknya dibuang.
     *
     * Kolom akun kategori sengaja TIDAK diisi dari kunci generik. Mengisinya
     * akan mengunci kategori ke akun gabungan lama -- `linkDefaults()` hanya
     * mengisi kolom yang null, jadi kategori itu tidak akan pernah ikut pindah
     * ke akun per kelas saat template COA diterapkan ulang, justru kebalikan
     * dari tujuan pemecahannya.
     */
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('account_mappings')) {
            return;
        }

        $connection = DB::connection('tenant');

        $fallbacks = [
            'fixed_assets.accumulated_depreciation' => 'fixed_assets.equipment_accumulated_depreciation',
            'fixed_assets.depreciation_expense' => 'fixed_assets.equipment_depreciation_expense',
        ];

        foreach ($fallbacks as $genericKey => $classKey) {
            $accountId = $connection->table('account_mappings')
                ->where('mapping_key', $genericKey)
                ->where('is_active', true)
                ->value('account_id');

            if ($accountId === null) {
                continue;
            }

            $classMapping = $connection->table('account_mappings')
                ->where('mapping_key', $classKey)
                ->first();

            if ($classMapping === null) {
                $connection->table('account_mappings')->insert([
                    'mapping_key' => $classKey,
                    'module' => 'fixed_assets',
                    'account_id' => $accountId,
                    'is_required' => false,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ($classMapping->account_id === null) {
                $connection->table('account_mappings')
                    ->where('mapping_key', $classKey)
                    ->update(['account_id' => $accountId, 'updated_at' => now()]);
            }
        }

        $connection->table('account_mappings')
            ->whereIn('mapping_key', array_keys($fallbacks))
            ->delete();
    }

    public function down(): void
    {
        // Pembersihan data satu arah: kunci generiknya tidak ada lagi di config,
        // jadi mengembalikan barisnya hanya memunculkan field ganda itu lagi.
    }
};
