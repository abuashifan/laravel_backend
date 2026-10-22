<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sheet yang dibaca sebuah batch impor.
 *
 * Satu berkas .xlsx boleh berisi banyak sheet dan user memilih sheet mana yang
 * diimpor. Pilihannya disimpan di batch karena berkasnya dibaca ulang di
 * beberapa langkah (pemetaan kolom, pratinjau, commit, buka ulang batch) dan
 * setiap langkah harus membaca sheet yang SAMA. Null = sheet pertama (CSV, dan
 * semua batch lama).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('import_batches', function (Blueprint $table) {
            $table->string('sheet_name')->nullable()->after('file_hash');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('import_batches', function (Blueprint $table) {
            $table->dropColumn('sheet_name');
        });
    }
};
