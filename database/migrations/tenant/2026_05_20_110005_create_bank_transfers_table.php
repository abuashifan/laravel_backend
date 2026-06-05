<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number')->unique();
            $table->date('transfer_date');
            $table->unsignedBigInteger('from_cash_bank_account_id');
            $table->unsignedBigInteger('to_cash_bank_account_id');
            $table->string('currency_code', 3)->default('IDR');
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('amount', 18, 2);
            $table->string('status', 30)->default('draft');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['transfer_date']);
            $table->index(['from_cash_bank_account_id', 'transfer_date']);
            $table->index(['to_cash_bank_account_id', 'transfer_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transfers');
    }
};

