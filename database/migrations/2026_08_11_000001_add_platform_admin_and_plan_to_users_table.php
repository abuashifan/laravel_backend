<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dua kolom untuk pengelolaan client oleh owner aplikasi:
 *
 * - is_platform_admin menandai akun pengelola aplikasi. Sengaja terpisah dari
 *   role di company_users, yang lingkupnya selalu satu perusahaan.
 * - plan_id mengikat paket langganan ke client, bukan ke perusahaan seperti
 *   tabel subscriptions. Kuota jumlah perusahaan harus bisa dibaca sebelum
 *   client punya perusahaan sama sekali, jadi tidak bisa diambil dari sana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('status');
            $table->foreignId('plan_id')->nullable()->after('is_platform_admin')
                ->constrained('plans')->nullOnDelete();

            $table->index('is_platform_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->dropColumn(['plan_id', 'is_platform_admin']);
        });
    }
};
