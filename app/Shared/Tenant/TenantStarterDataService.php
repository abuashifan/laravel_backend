<?php

namespace App\Shared\Tenant;

use App\Shared\Models\TenantDatabase;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Master data bawaan untuk tenant yang baru dibangun: satu gudang dan satu
 * satuan, supaya langkah Master Data di wizard tidak menuntut user mengetik
 * sesuatu yang hampir selalu sama. Syarat pembayaran tidak di sini karena
 * sudah diisi migration `create_payment_terms_table` sendiri.
 *
 * Sengaja TIDAK berupa migration tenant: puluhan test membuat satuan `PCS` dan
 * gudangnya sendiri di atas tenant hasil migrate, dan baris bawaan di migration
 * akan bentrok dengan unique `code` mereka. Dipanggil hanya dari jalur yang
 * menghasilkan tenant kosong baru -- pembuatan perusahaan dan perbaikan tenant.
 *
 * Masing-masing hanya diisi kalau tabelnya masih kosong, jadi aman dipanggil
 * ulang dan tidak pernah menambah baris ke tenant yang sudah berisi.
 */
class TenantStarterDataService
{
    public const WAREHOUSE_CODE = 'UTAMA';

    public const UNIT_CODE = 'PCS';

    public function __construct(private readonly TenantConnectionManager $connectionManager) {}

    /**
     * Kegagalan dilaporkan, tidak dilempar: perusahaan tetap bisa dipakai
     * tanpa data bawaan ini -- user tinggal menambahkannya di langkah Master
     * Data. Menggagalkan pembuatan perusahaan karenanya jauh lebih merugikan.
     */
    public function seed(TenantDatabase $tenantDatabase): void
    {
        try {
            $this->connectionManager->connect($tenantDatabase);

            $tenant = DB::connection('tenant');
            $now = now();

            if (! $tenant->table('warehouses')->exists()) {
                $tenant->table('warehouses')->insert([
                    'code' => self::WAREHOUSE_CODE,
                    'name' => 'Gudang Utama',
                    'is_default' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if (! $tenant->table('units')->exists()) {
                $tenant->table('units')->insert([
                    'code' => self::UNIT_CODE,
                    'name' => 'PCS',
                    'precision' => 0,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        } finally {
            try {
                $this->connectionManager->disconnect();
            } catch (Throwable) {
                // Kegagalan memutus koneksi tidak relevan bagi pemanggil.
            }
        }
    }
}
