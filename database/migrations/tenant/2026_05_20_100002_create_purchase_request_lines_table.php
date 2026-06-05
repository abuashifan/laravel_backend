<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_request_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_request_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_code')->nullable();
            $table->text('description');
            $table->decimal('quantity', 18, 4);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('estimated_unit_price', 18, 2)->default(0);
            $table->decimal('estimated_line_total', 18, 2)->default(0);
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('source_line_type')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('purchase_request_id')->references('id')->on('purchase_requests')->cascadeOnDelete();
            $table->index('product_id');
            $table->index('warehouse_id');
            $table->index(['source_line_type', 'source_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_lines');
    }
};
