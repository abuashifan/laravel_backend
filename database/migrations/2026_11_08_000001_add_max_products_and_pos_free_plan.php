<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 POS: paket Kasir punya batas jumlah produk (`max_products`, null =
 * tanpa batas) dan ada paket "Kasir Gratis" yang bisa diambil sendiri dari
 * aplikasi kasir setelah masa coba habis. Harga paket berbayar diisi pemilik
 * produk (lihat PlanSeeder); di sini hanya struktur dan nilai awal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('max_products')->nullable()->after('max_users');
        });

        DB::table('plans')->where('code', 'pos')->update([
            'name' => 'Kasir',
            'description' => 'Paket Finlite Kasir: 3 pengguna, 1 toko, produk tanpa batas.',
            'updated_at' => now(),
        ]);

        if (! DB::table('plans')->where('code', 'pos_free')->exists()) {
            DB::table('plans')->insert([
                'name' => 'Kasir Gratis',
                'code' => 'pos_free',
                'product' => 'pos',
                'description' => 'Gratis selamanya: 1 pengguna, 1 toko, maksimal 30 produk.',
                'max_users' => 1,
                'max_products' => 30,
                'max_companies' => 1,
                'monthly_price' => 0,
                'yearly_price' => 0,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $freeId = DB::table('plans')->where('code', 'pos_free')->value('id');
        if ($freeId) {
            DB::table('subscriptions')->where('plan_id', $freeId)->delete();
            DB::table('plans')->where('id', $freeId)->delete();
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('max_products');
        });
    }
};
