<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delivery_order_id');
            $table->unsignedBigInteger('sales_order_line_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_code')->nullable();
            $table->text('description');
            $table->decimal('quantity', 18, 4);
            $table->decimal('invoiced_quantity', 18, 4)->default(0);
            $table->decimal('returned_quantity', 18, 4)->default(0);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('source_line_type')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->foreign('delivery_order_id')->references('id')->on('delivery_orders')->cascadeOnDelete();
            $table->index('sales_order_line_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_order_lines');
    }
};
