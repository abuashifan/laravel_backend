<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_reconciliation_id');
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('journal_entry_line_id');
            $table->date('journal_date');
            $table->string('journal_number');
            $table->text('description')->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->boolean('is_cleared')->default(false);
            $table->date('cleared_date')->nullable();
            $table->unsignedInteger('line_order')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('bank_reconciliation_id')->references('id')->on('bank_reconciliations')->cascadeOnDelete();
            $table->index(['bank_reconciliation_id', 'line_order']);
            $table->index('journal_entry_id');
            $table->index('journal_entry_line_id');
            $table->index('journal_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_lines');
    }
};

