<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->cascadeOnDelete();
            $table->index(['company_id', 'is_active']);
            $table->dropUnique('roles_slug_unique');
            $table->unique(['company_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'slug']);
            $table->unique('slug');
            $table->dropIndex(['company_id', 'is_active']);
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
