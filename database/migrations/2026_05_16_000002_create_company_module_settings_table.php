<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_module_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();

            $table->boolean('sales_enabled')->default(true);
            $table->boolean('purchase_enabled')->default(true);
            $table->boolean('cash_bank_enabled')->default(true);
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('warehouse_enabled')->default(false);
            $table->boolean('fixed_asset_enabled')->default(false);
            $table->boolean('approval_enabled')->default(false);
            $table->boolean('tax_enabled')->default(false);
            $table->boolean('reports_enabled')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_module_settings');
    }
};

