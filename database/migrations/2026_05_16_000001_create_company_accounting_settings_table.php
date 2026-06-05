<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_accounting_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();

            $table->string('base_currency', 3)->default('IDR');
            $table->unsignedTinyInteger('amount_precision')->default(2);
            $table->unsignedTinyInteger('quantity_precision')->default(4);
            $table->string('rounding_method')->default('half_up');
            $table->string('transaction_workflow_mode')->default('simple_auto_post');
            $table->boolean('auto_post_transactions')->default(true);

            $table->boolean('allow_edit_transactions')->default(true);
            $table->boolean('allow_edit_posted_transactions')->default(true);
            $table->boolean('allow_void_transactions')->default(true);
            $table->boolean('hide_voided_transactions')->default(true);
            $table->boolean('require_void_reason')->default(true);

            $table->boolean('approval_enabled')->default(false);
            $table->boolean('tax_enabled')->default(false);

            $table->boolean('allow_backdated_transactions')->default(true);
            $table->integer('max_backdate_days')->nullable();
            $table->boolean('allow_future_transactions')->default(false);
            $table->integer('max_future_days')->nullable()->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_accounting_settings');
    }
};

