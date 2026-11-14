<?php

use App\Shared\Permission\PermissionCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finlite Gudang, Fase 0: paket Gudang dan Gudang Gratis (harga 0 sebagai
 * tempat, ditentukan pemilik produk) serta kunci izin `gudang.*`.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['code' => 'gudang', 'name' => 'Gudang', 'description' => 'Paket Finlite Gudang: 3 pengguna, barang tanpa batas.', 'max_users' => 3, 'max_products' => null],
            ['code' => 'gudang_free', 'name' => 'Gudang Gratis', 'description' => 'Gratis selamanya: 1 pengguna, maksimal 100 barang.', 'max_users' => 1, 'max_products' => 100],
        ] as $plan) {
            if (! DB::table('plans')->where('code', $plan['code'])->exists()) {
                DB::table('plans')->insert($plan + [
                    'product' => 'gudang', 'max_companies' => 1, 'monthly_price' => 0, 'yearly_price' => 0,
                    'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        if (! Schema::hasTable('permissions')) {
            return;
        }
        $catalog = app(PermissionCatalogService::class);
        foreach ((array) config('permissions.matrix_keys', []) as $index => $key) {
            if (! str_starts_with((string) $key, 'gudang.')) {
                continue;
            }
            $values = $catalog->fromKey($key, $index + 1);
            unset($values['key']);
            $values['updated_at'] = now();
            if (DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->where('key', $key)->update($values);
            } else {
                DB::table('permissions')->insert(['key' => $key, ...$values, 'created_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('key', 'like', 'gudang.%')->delete();
        }
        $ids = DB::table('plans')->whereIn('code', ['gudang', 'gudang_free'])->pluck('id');
        DB::table('subscriptions')->whereIn('plan_id', $ids)->delete();
        DB::table('plans')->whereIn('id', $ids)->delete();
    }
};
