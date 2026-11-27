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

        // Jembatan Gudang -> Kasir (Fase 4a). Satu baris pengaturan per tenant.
        $schema->create('gudang_pos_bridge_config', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('connected_by')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();
        });

        // Satu surat jalan = satu kiriman ke Kasir. Kunci unik pada dokumen menjamin
        // surat jalan yang sama tidak terkirim dua kali.
        $schema->create('gudang_pos_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained('gudang_stock_documents');
            // Kunci idempoten yang dibaca Kasir.
            $table->uuid('receipt_key')->unique();
            // pending | held | failed | delivered | received | discrepancy
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            // Baris kiriman dan hasil terima: [{sku, name, unit, expected, actual}]
            $table->json('summary')->nullable();
            $table->unsignedBigInteger('sent_by');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('received_by', 150)->nullable();
            $table->text('receive_note')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->text('acknowledge_note')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('gudang_pos_dispatches');
        Schema::connection($this->connection)->dropIfExists('gudang_pos_bridge_config');
    }
};
