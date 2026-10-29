<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_invitations', function (Blueprint $table) {
            $table->boolean('full_access')->default(false);
            $table->json('permissions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_invitations', function (Blueprint $table) {
            $table->dropColumn(['full_access', 'permissions']);
        });
    }
};
