<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_code', 50)->nullable();
            $table->string('product_name');
            $table->string('product_type', 20)->default('goods');
            $table->foreignId('product_category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->boolean('is_stock_item')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();

            // optional account fields for future flexibility
            $table->foreignId('sales_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('purchase_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('inventory_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('cogs_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();

            $table->timestamps();

            $table->unique('product_code');
            $table->index('product_name');
            $table->index('product_type');
            $table->index('product_category_id');
            $table->index('unit_id');
            $table->index('is_stock_item');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

