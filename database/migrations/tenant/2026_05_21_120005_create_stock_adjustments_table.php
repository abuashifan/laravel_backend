<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_number')->unique();
            $table->date('adjustment_date');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->string('status', 30)->default('draft');
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->unsignedInteger('revision_no')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('adjustment_date');
            $table->index('warehouse_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};

