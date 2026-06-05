<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->date('payment_date');
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('vendor_bill_id')->nullable();
            $table->unsignedBigInteger('cash_bank_account_id');
            $table->string('currency_code', 3)->default('IDR');
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('amount', 18, 2);
            $table->string('status', 30)->default('draft');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_number')->nullable();
            $table->unsignedInteger('source_revision')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['vendor_id', 'payment_date']);
            $table->index('vendor_bill_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payments');
    }
};
