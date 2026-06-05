<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->string('movement_number')->unique();
            $table->date('movement_date');
            $table->string('movement_type', 50);
            $table->string('direction', 10)->nullable();
            $table->string('status', 30)->default('draft');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_number')->nullable();
            $table->unsignedInteger('source_revision')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->decimal('total_quantity', 18, 4)->default(0);
            $table->decimal('total_value', 18, 2)->default(0);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->unsignedBigInteger('reversed_by_id')->nullable();
            $table->unsignedInteger('revision_no')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('movement_date');
            $table->index('movement_type');
            $table->index('status');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};

