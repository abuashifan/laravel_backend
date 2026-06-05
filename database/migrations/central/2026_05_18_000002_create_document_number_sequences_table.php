<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('document_type');
            $table->foreignId('fiscal_year_id')->nullable()->constrained('fiscal_years')->nullOnDelete();
            $table->string('period_key');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index('document_type');
            $table->index('fiscal_year_id');
            $table->unique(['company_id', 'document_type', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_number_sequences');
    }
};

