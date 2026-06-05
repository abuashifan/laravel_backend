<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('mapping_key');
            $table->string('module', 50);
            $table->foreignId('account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('mapping_key');
            $table->index('module');
            $table->index('account_id');
            $table->index('is_required');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_mappings');
    }
};

