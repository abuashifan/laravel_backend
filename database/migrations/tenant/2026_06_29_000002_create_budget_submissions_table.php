<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('budget_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('budget_period_id');
            $table->unsignedBigInteger('department_id');
            $table->enum('status', ['draft', 'submitted', 'approved_by_head', 'approved', 'rejected'])->default('draft');
            $table->unsignedTinyInteger('revision_number')->default(1);
            $table->unsignedBigInteger('submitted_by_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by_head_id')->nullable();
            $table->timestamp('approved_by_head_at')->nullable();
            $table->unsignedBigInteger('approved_by_finance_id')->nullable();
            $table->timestamp('approved_by_finance_at')->nullable();
            $table->unsignedBigInteger('rejected_by_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'budget_period_id']);
            $table->index(['company_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('budget_submissions');
    }
};
