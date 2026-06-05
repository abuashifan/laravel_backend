<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opname_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_opname_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('system_quantity', 18, 4)->default(0);
            $table->decimal('physical_quantity', 18, 4)->nullable();
            $table->decimal('difference_quantity', 18, 4)->default(0);
            $table->decimal('average_cost', 18, 6)->default(0);
            $table->decimal('difference_value', 18, 2)->default(0);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('counted_by')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('stock_opname_id')->references('id')->on('stock_opnames')->cascadeOnDelete();
            $table->index('product_id');
            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_lines');
    }
};

