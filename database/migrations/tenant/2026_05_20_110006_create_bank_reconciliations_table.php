<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->string('reconciliation_number')->unique();
            $table->unsignedBigInteger('cash_bank_account_id');
            $table->date('statement_start_date');
            $table->date('statement_end_date');
            $table->decimal('statement_opening_balance', 18, 2)->default(0);
            $table->decimal('statement_ending_balance', 18, 2)->default(0);
            $table->string('status', 30)->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['cash_bank_account_id', 'statement_end_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};

