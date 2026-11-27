<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pos';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        // Kotak masuk kiriman dari Finlite Gudang (Fase 4a Gudang). Stok toko
        // baru bertambah saat petugas mengonfirmasi jumlah diterima.
        $schema->create('pos_gudang_receipts', function (Blueprint $table) {
            $table->id();
            // Kunci idempoten dari Gudang: kiriman yang sama tidak tercatat dua kali.
            $table->uuid('receipt_key')->unique();
            $table->string('number', 40);
            $table->string('source_name', 100);
            $table->timestamp('shipped_at');
            // pending | received | discrepancy
            $table->string('status', 20)->default('pending');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->string('received_by_name', 150)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        $schema->create('pos_gudang_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('pos_gudang_receipts')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('pos_products')->nullOnDelete();
            $table->string('sku', 64);
            $table->string('name', 160);
            $table->string('unit', 24);
            $table->decimal('expected_quantity', 14, 3);
            $table->decimal('actual_quantity', 14, 3)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('pos_gudang_receipt_lines');
        Schema::connection($this->connection)->dropIfExists('pos_gudang_receipts');
    }
};
