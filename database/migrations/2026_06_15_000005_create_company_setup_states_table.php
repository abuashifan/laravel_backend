<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_setup_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->string('status', 30)->default('not_started');
            $table->string('current_step', 80)->default('company_profile');
            $table->date('opening_date')->nullable();
            $table->json('completed_steps')->nullable();
            $table->json('validation_errors')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'current_step']);
            $table->index('opening_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_setup_states');
    }
};
