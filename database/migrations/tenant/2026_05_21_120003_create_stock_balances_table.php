<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->decimal('quantity_on_hand', 18, 4)->default(0);
            $table->decimal('quantity_reserved', 18, 4)->default(0);
            $table->decimal('quantity_available', 18, 4)->default(0);
            $table->decimal('average_cost', 18, 6)->default(0);
            $table->decimal('total_value', 18, 2)->default(0);
            $table->unsignedBigInteger('last_movement_id')->nullable();
            $table->timestamp('last_movement_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id']);
            $table->index('product_id');
            $table->index('warehouse_id');
            $table->index('quantity_on_hand');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};

