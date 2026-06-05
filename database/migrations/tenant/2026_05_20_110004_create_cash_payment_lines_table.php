<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_payment_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cash_payment_id');
            $table->unsignedBigInteger('account_id');
            $table->decimal('amount', 18, 2);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedInteger('line_order')->default(1);
            $table->string('source_line_type')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('cash_payment_id')->references('id')->on('cash_payments')->cascadeOnDelete();
            $table->index(['cash_payment_id', 'line_order']);
            $table->index('account_id');
            $table->index('department_id');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_payment_lines');
    }
};

