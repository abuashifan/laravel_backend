<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_quotation_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_code')->nullable();
            $table->text('description');
            $table->decimal('quantity', 18, 4);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 18, 2)->nullable();
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('subtotal_after_discount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('source_line_type')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('sales_quotation_id')->references('id')->on('sales_quotations')->cascadeOnDelete();
            $table->index('product_id');
            $table->index(['source_line_type', 'source_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_quotation_lines');
    }
};
