<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('max_users')->default(1);
            $table->unsignedInteger('max_companies')->default(1);
            $table->unsignedInteger('max_transactions_per_month')->nullable();
            $table->boolean('can_use_sales')->default(false);
            $table->boolean('can_use_purchases')->default(false);
            $table->boolean('can_use_inventory')->default(false);
            $table->boolean('can_export_reports')->default(false);
            $table->decimal('monthly_price', 15, 2)->default(0);
            $table->decimal('yearly_price', 15, 2)->default(0);
            $table->string('status')->default('active');
            $table->json('features')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};

