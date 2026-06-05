<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_receipt_id');
            $table->unsignedBigInteger('sales_invoice_id')->nullable();
            $table->unsignedBigInteger('billing_invoice_id')->nullable();
            $table->decimal('amount', 18, 2);
            $table->text('description')->nullable();
            $table->string('source_line_type')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('sales_receipt_id')->references('id')->on('sales_receipts')->cascadeOnDelete();
            $table->index('sales_invoice_id');
            $table->index('billing_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_receipt_lines');
    }
};
