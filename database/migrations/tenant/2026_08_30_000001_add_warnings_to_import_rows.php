<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kanal peringatan untuk pratinjau impor.
 *
 * Sampai sekarang sebuah baris hanya bisa `valid` atau `invalid`: satu-satunya
 * cara profil memberi tahu sesuatu ke user adalah menggagalkan barisnya. Itu
 * terlalu tumpul untuk data warisan — umur manfaat yang beda dari default
 * kategori atau akumulasi penyusutan yang jauh dari garis lurus BOLEH saja
 * benar (pembukuan lama klien memang begitu), tapi user tetap harus melihatnya
 * sebelum menekan Commit.
 *
 * Peringatan tidak pernah mengubah status baris. Baris dengan peringatan tetap
 * `valid` dan tetap ikut ter-commit.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('import_rows', function (Blueprint $table) {
            $table->json('warnings')->nullable()->after('errors');
        });

        // Hitungan tingkat batch supaya pratinjau bisa mengatakan "3 baris
        // berperingatan" tanpa user harus membuka halaman baris satu per satu.
        Schema::connection('tenant')->table('import_batches', function (Blueprint $table) {
            $table->unsignedInteger('warning_rows')->default(0)->after('failed_rows');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('import_rows', function (Blueprint $table) {
            $table->dropColumn('warnings');
        });

        Schema::connection('tenant')->table('import_batches', function (Blueprint $table) {
            $table->dropColumn('warning_rows');
        });
    }
};
