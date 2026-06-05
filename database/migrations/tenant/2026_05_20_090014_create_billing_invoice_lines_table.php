<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('billing_invoice_id');
            $table->unsignedBigInteger('sales_invoice_line_id')->nullable();
            $table->text('description');
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('source_line_type')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('billing_invoice_id')->references('id')->on('billing_invoices')->cascadeOnDelete();
            $table->index('sales_invoice_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoice_lines');
    }
};
