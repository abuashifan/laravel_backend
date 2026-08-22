<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add-on "user tambahan" menempel di client, bukan di perusahaan.
     *
     * Konsekuensinya disengaja: sekali dibeli, jatah tambahan itu berlaku di
     * **setiap** perusahaan milik client. Client Pro (5 user) dengan add-on 5
     * berarti 10 user di masing-masing dari 3 perusahaannya.
     *
     * Nol, bukan null — tidak ada keadaan "ikut paket" untuk add-on seperti
     * pada `company_quota`/`user_quota`; yang ada hanya "belum beli".
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('extra_users')->default(0)->after('user_quota');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('extra_users');
        });
    }
};
