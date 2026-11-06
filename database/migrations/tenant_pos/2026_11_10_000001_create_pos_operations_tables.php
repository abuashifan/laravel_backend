<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3a Finlite Kasir: pemasok & stok masuk, retur penjualan, stok opname,
 * dan kunci idempoten penjualan (antrean ulang saat sinyal putus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            // Dikirim aplikasi kasir; simpan ulang dengan kunci sama = transaksi yang sama.
            $table->string('idempotency_key', 64)->nullable()->unique();
        });

        Schema::create('pos_suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            // Sisa hutang toko ke pemasok.
            $table->decimal('payable_balance', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('pos_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('supplier_id')->nullable()->constrained('pos_suppliers')->nullOnDelete();
            $table->timestamp('received_at');
            $table->unsignedBigInteger('user_id');
            $table->string('user_name', 150)->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            // Bagian total yang menjadi hutang ke pemasok.
            $table->decimal('payable_amount', 18, 2)->default(0);
            // cash | transfer | qris -- cara membayar bagian yang dibayar.
            $table->string('payment_method', 20)->nullable();
            $table->string('reference', 60)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('received_at');
        });

        Schema::create('pos_purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('pos_purchases')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('pos_products')->nullOnDelete();
            $table->string('product_name', 150);
            $table->string('unit', 20)->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();
        });

        Schema::create('pos_supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('supplier_id')->constrained('pos_suppliers');
            $table->decimal('amount', 18, 2);
            $table->string('method', 20);
            $table->timestamp('paid_at');
            $table->unsignedBigInteger('user_id');
            $table->string('user_name', 150)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_sale_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('sale_id')->constrained('pos_sales');
            // Shift kasir yang memproses retur (uang tunai keluar dari laci shift ini).
            $table->foreignId('shift_id')->constrained('pos_shifts');
            $table->unsignedBigInteger('user_id');
            $table->string('user_name', 150)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('pos_customers')->nullOnDelete();
            $table->timestamp('returned_at');
            $table->decimal('total', 18, 2)->default(0);
            // cash | transfer | qris | credit (potong kasbon)
            $table->string('refund_method', 20);
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index('returned_at');
        });

        Schema::create('pos_sale_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('pos_sale_returns')->cascadeOnDelete();
            $table->foreignId('sale_line_id')->constrained('pos_sale_lines');
            $table->foreignId('product_id')->nullable()->constrained('pos_products')->nullOnDelete();
            $table->string('product_name', 150);
            $table->decimal('quantity', 14, 3);
            // Nilai uang kembali untuk baris ini (sudah termasuk bagian diskon).
            $table->decimal('amount', 18, 2);
            $table->decimal('unit_cost', 18, 2)->default(0);
            // false = barang rusak, tidak kembali ke stok.
            $table->boolean('restock')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->timestamp('counted_at');
            $table->unsignedBigInteger('user_id');
            $table->string('user_name', 150)->nullable();
            // Nilai selisih (harga modal x selisih jumlah); minus = barang hilang.
            $table->decimal('difference_value', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained('pos_stock_counts')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('pos_products')->nullOnDelete();
            $table->string('product_name', 150);
            $table->decimal('system_quantity', 14, 3);
            $table->decimal('counted_quantity', 14, 3);
            $table->decimal('difference', 14, 3);
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['pos_stock_count_lines', 'pos_stock_counts', 'pos_sale_return_lines', 'pos_sale_returns', 'pos_supplier_payments', 'pos_purchase_lines', 'pos_purchases', 'pos_suppliers'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('pos_sales', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
