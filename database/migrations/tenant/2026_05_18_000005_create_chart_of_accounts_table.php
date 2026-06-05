<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_code', 50);
            $table->string('account_name');
            $table->string('account_type', 20);
            $table->foreignId('parent_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('normal_balance', 10);
            $table->boolean('is_cash_bank')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system_default')->default(false);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('account_code');
            $table->index('account_type');
            $table->index('parent_account_id');
            $table->index('is_cash_bank');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};

