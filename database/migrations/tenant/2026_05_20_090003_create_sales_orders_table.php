<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->date('order_date');
            $table->unsignedBigInteger('customer_id');
            $table->text('customer_address')->nullable();
            $table->text('shipping_address')->nullable();
            $table->unsignedBigInteger('quotation_id')->nullable();
            $table->string('quotation_number')->nullable();
            $table->string('customer_po_number')->nullable();
            $table->string('contract_number')->nullable();
            $table->unsignedBigInteger('salesperson_id')->nullable();
            $table->string('currency_code', 3)->default('IDR');
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->boolean('is_taxable')->default(false);
            $table->boolean('tax_included')->default(false);
            $table->boolean('has_down_payment')->default(false);
            $table->string('status', 30)->default('draft');
            $table->decimal('subtotal_before_discount', 18, 2)->default(0);
            $table->decimal('line_discount_total', 18, 2)->default(0);
            $table->string('header_discount_type')->nullable();
            $table->decimal('header_discount_value', 18, 2)->nullable();
            $table->decimal('header_discount_amount', 18, 2)->default(0);
            $table->decimal('subtotal_after_discount', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->decimal('delivered_amount', 18, 2)->default(0);
            $table->decimal('invoiced_amount', 18, 2)->default(0);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_number')->nullable();
            $table->unsignedInteger('source_revision')->nullable();
            $table->unsignedInteger('revision_no')->default(1);
            $table->string('form_template')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'order_date']);
            $table->index('quotation_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};
