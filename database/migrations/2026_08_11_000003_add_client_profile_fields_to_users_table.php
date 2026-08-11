<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data kontak client yang dicatat owner aplikasi.
 *
 * `company_name` dan `job_title` di sini murni informasi kontak — nama tempat
 * client bekerja dan jabatannya — dan tidak ada hubungannya dengan tabel
 * `companies` (tenant yang dibuat client di aplikasi). Sengaja disimpan di
 * database pusat, bukan di tenant, karena ini catatan hubungan bisnis antara
 * owner aplikasi dan client, bukan data operasional perusahaan mereka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('phone');
            $table->string('job_title')->nullable()->after('company_name');
            $table->text('address')->nullable()->after('job_title');
            $table->text('notes')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notes', 'address', 'job_title', 'company_name']);
        });
    }
};
