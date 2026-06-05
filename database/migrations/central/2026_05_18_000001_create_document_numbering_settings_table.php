<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_numbering_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('document_type');
            $table->string('name')->nullable();
            $table->string('prefix');
            $table->string('format')->default('{PREFIX}-{YEAR}-{NUMBER}');
            $table->string('reset_period')->default('fiscal_year');
            $table->unsignedTinyInteger('padding')->default(6);
            $table->string('mode')->default('auto');
            $table->boolean('allow_manual_number')->default(false);
            $table->boolean('allow_duplicate_number')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->unique(['company_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_numbering_settings');
    }
};

