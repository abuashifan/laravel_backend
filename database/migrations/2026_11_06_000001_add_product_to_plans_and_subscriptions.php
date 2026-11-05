<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 0 POS: paket dan langganan per produk. Paket dan langganan lama
 * otomatis menjadi milik `accounting`; `users.plan_id` tetap berarti paket
 * akuntansi. Paket POS pertama dibuat dengan harga 0 sebagai tempat --
 * harga dan isinya ditentukan pemilik produk sebelum Fase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('product', 30)->default('accounting')->after('code');
            $table->index('product');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('product', 30)->default('accounting')->after('plan_id');
            $table->index(['user_id', 'product']);
        });

        if (! DB::table('plans')->where('code', 'pos')->exists()) {
            DB::table('plans')->insert([
                'name' => 'Kasir (POS)',
                'code' => 'pos',
                'product' => 'pos',
                'description' => 'Paket Finlite POS. Harga dan kuota akan ditentukan.',
                'max_users' => 3,
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
        DB::table('subscriptions')->where('product', '!=', 'accounting')->delete();
        DB::table('plans')->where('product', '!=', 'accounting')->delete();

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'product']);
            $table->dropColumn('product');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex(['product']);
            $table->dropColumn('product');
        });
    }
};
