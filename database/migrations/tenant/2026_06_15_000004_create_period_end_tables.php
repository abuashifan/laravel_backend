<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_end_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_number')->unique();
            $table->unsignedBigInteger('accounting_period_id');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('period', 7);
            $table->string('status', 30)->default('draft');
            $table->json('checklist_snapshot')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['period', 'status']);
            $table->index('accounting_period_id');
        });

        Schema::create('period_end_run_routines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_end_run_id')->constrained('period_end_runs')->cascadeOnDelete();
            $table->string('period', 7);
            $table->string('routine_key', 80);
            $table->string('routine_name');
            $table->string('status', 30)->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['period', 'routine_key']);
            $table->index(['period', 'routine_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_end_run_routines');
        Schema::dropIfExists('period_end_runs');
    }
};
