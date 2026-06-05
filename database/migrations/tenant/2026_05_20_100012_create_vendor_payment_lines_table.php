<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_payment_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_payment_id');
            $table->unsignedBigInteger('vendor_bill_id')->nullable();
            $table->decimal('amount', 18, 2);
            $table->text('description')->nullable();
            $table->string('source_line_type')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->foreign('vendor_payment_id')->references('id')->on('vendor_payments')->cascadeOnDelete();
            $table->index('vendor_bill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payment_lines');
    }
};
