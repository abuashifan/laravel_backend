<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('fiscal_year_closings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fiscal_year_id');
            $table->unsignedBigInteger('closed_by_user_id')->nullable();
            $table->unsignedBigInteger('reopened_by_user_id')->nullable();
            $table->string('closing_reference')->nullable();
            $table->unsignedBigInteger('retained_earnings_account_id')->nullable();
            $table->decimal('retained_earnings_amount', 24, 8)->default(0);
            $table->text('closing_notes')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->string('status')->default('completed');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('fiscal_year_id');
            $table->index('status');
            $table->index('closed_at');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('fiscal_year_closings');
    }
};

