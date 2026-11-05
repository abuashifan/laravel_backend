<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skema awal Finlite POS. Tenant POS terpisah dari tenant akuntansi
 * (config/tenant.php `products.pos`), jadi tabel di sini tidak menyentuh
 * buku besar sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50)->nullable()->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->string('name', 150);
            $table->foreignId('category_id')->nullable()->constrained('pos_categories')->nullOnDelete();
            $table->string('unit', 20)->default('pcs');
            $table->decimal('price', 18, 2)->default(0);
            $table->decimal('cost', 18, 2)->default(0);
            // Stok opsional per produk: jasa/barang curah tidak perlu dilacak.
            $table->boolean('track_stock')->default(false);
            $table->decimal('stock', 14, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index('is_active');
        });

        Schema::create('pos_customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            // Sisa kasbon (piutang) pelanggan saat ini.
            $table->decimal('credit_balance', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index('phone');
        });

        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('user_name', 150)->nullable();
            $table->timestamp('opened_at');
            $table->decimal('opening_cash', 18, 2)->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 18, 2)->nullable();
            $table->decimal('counted_cash', 18, 2)->nullable();
            $table->decimal('cash_difference', 18, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('pos_sales', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('shift_id')->constrained('pos_shifts');
            $table->unsignedBigInteger('user_id');
            $table->string('user_name', 150)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('pos_customers')->nullOnDelete();
            $table->timestamp('sold_at');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            // cash | transfer | qris | credit (kasbon)
            $table->string('payment_method', 20);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('change_amount', 18, 2)->default(0);
            // Bagian total yang menjadi kasbon pelanggan.
            $table->decimal('credit_amount', 18, 2)->default(0);
            // none | digital | print
            $table->string('receipt_mode', 20)->default('none');
            $table->string('status', 20)->default('completed');
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('sold_at');
            $table->index('status');
        });

        Schema::create('pos_sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('pos_products')->nullOnDelete();
            $table->string('product_name', 150);
            $table->string('sku', 50)->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            // HPP per unit saat dijual, untuk laporan laba kotor.
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('pos_products')->cascadeOnDelete();
            // sale | void | adjustment | restock
            $table->string('type', 20);
            $table->decimal('quantity', 14, 3);
            $table->decimal('stock_after', 14, 3);
            $table->string('reference_type', 30)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('notes')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
        });

        Schema::create('pos_credit_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('customer_id')->constrained('pos_customers');
            $table->foreignId('shift_id')->nullable()->constrained('pos_shifts')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            // cash | transfer | qris
            $table->string('method', 20);
            $table->timestamp('paid_at');
            $table->unsignedBigInteger('user_id');
            $table->string('user_name', 150)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['pos_settings', 'pos_credit_payments', 'pos_stock_movements', 'pos_sale_lines', 'pos_sales', 'pos_shifts', 'pos_customers', 'pos_products', 'pos_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
