<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_return_id');
            $table->unsignedBigInteger('vendor_bill_line_id')->nullable();
            $table->unsignedBigInteger('goods_receipt_line_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_code')->nullable();
            $table->text('description');
            $table->decimal('quantity', 18, 4);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('expense_account_id')->nullable();
            $table->string('source_line_type')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->foreign('purchase_return_id')->references('id')->on('purchase_returns')->cascadeOnDelete();
            $table->index('vendor_bill_line_id');
            $table->index('goods_receipt_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_lines');
    }
};
