<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_users', function (Blueprint $table) {
            $table->boolean('full_access')->default(false);
            $table->boolean('is_owner')->default(false);
        });

        Schema::create('company_user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_user_id')->constrained('company_users')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['company_user_id', 'permission_id']);
        });

        // Pertahankan akses akun pengelola lingkungan uji sampai Fase 3.
        DB::table('company_users')->whereIn('role', ['owner', 'admin'])->update(['full_access' => true]);
        DB::table('company_users')->whereExists(function ($query): void {
            $query->selectRaw('1')->from('companies')
                ->whereColumn('companies.id', 'company_users.company_id')
                ->whereColumn('companies.created_by', 'company_users.user_id');
        })->update(['is_owner' => true, 'full_access' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user_permissions');
        Schema::table('company_users', function (Blueprint $table) {
            $table->dropColumn(['full_access', 'is_owner']);
        });
    }
};
