<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'gudang';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        // Harga beli per satuan dasar saat barang diterima (opsional). Hanya dipakai
        // jembatan ke Accounting untuk penerimaan tanpa PO; nilai persediaan tetap milik Accounting.
        $schema->table('gudang_stock_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 18, 2)->nullable();
        });

        // Jembatan Gudang -> Accounting (Fase 4b). Satu baris pengaturan per tenant.
        $schema->create('gudang_accounting_bridge_config', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            // Gudang dan pemasok di tenant Accounting (bukan id Gudang).
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->date('start_date')->nullable();
            $table->unsignedBigInteger('connected_by')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();
        });

        // Satu dokumen Gudang = satu kiriman. Penerimaan menjadi Goods Receipt Accounting,
        // penyesuaian dan selisih opname menjadi penyesuaian stok Accounting.
        $schema->create('gudang_accounting_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained('gudang_stock_documents');
            // receipt | adjustment
            $table->string('kind', 20);
            // pending | held | failed | sent
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            // Nomor dokumen dan jurnal di Accounting, mis. "GR-2026-000001 · JV-2026-000010".
            $table->string('reference', 255)->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('gudang_accounting_dispatches');
        $schema->dropIfExists('gudang_accounting_bridge_config');
        $schema->table('gudang_stock_movements', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
