<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_units', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('workspace_unit_members', function (Blueprint $table): void {
            $table->foreignId('unit_id')->constrained('workspace_units')->cascadeOnDelete();
            $table->unsignedBigInteger('company_user_id');
            $table->primary(['unit_id', 'company_user_id']);
            $table->index('company_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_unit_members');
        Schema::dropIfExists('workspace_units');
    }
};
