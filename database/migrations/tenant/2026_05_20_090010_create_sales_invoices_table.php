<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('customer_id');
            $table->text('customer_address')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_number')->nullable();
            $table->unsignedInteger('source_revision')->nullable();
            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->unsignedBigInteger('delivery_order_id')->nullable();
            $table->unsignedBigInteger('proforma_invoice_id')->nullable();
            $table->unsignedBigInteger('salesperson_id')->nullable();
            $table->string('currency_code', 3)->default('IDR');
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->boolean('is_taxable')->default(false);
            $table->boolean('tax_included')->default(false);
            $table->string('status', 30)->default('draft');
            $table->decimal('subtotal_before_discount', 18, 2)->default(0);
            $table->decimal('line_discount_total', 18, 2)->default(0);
            $table->string('header_discount_type')->nullable();
            $table->decimal('header_discount_value', 18, 2)->nullable();
            $table->decimal('header_discount_amount', 18, 2)->default(0);
            $table->decimal('subtotal_after_discount', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->decimal('applied_down_payment_amount', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('returned_amount', 18, 2)->default(0);
            $table->decimal('balance_due', 18, 2)->default(0);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('deposit_allocation_journal_entry_id')->nullable();
            $table->unsignedInteger('revision_no')->default(1);
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'invoice_date']);
            $table->index('sales_order_id');
            $table->index('delivery_order_id');
            $table->index('proforma_invoice_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
    }
};
