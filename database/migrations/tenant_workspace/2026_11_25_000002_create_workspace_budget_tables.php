<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_budget_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('period_id'); // Periode berada pada koneksi pusat.
            $table->foreignId('unit_id')->constrained('workspace_units');
            $table->unsignedBigInteger('approved_annual_id')->nullable();
            $table->unique(['period_id', 'unit_id']);
        });

        Schema::create('workspace_budget_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('workspace_budget_plans');
            $table->string('code', 24);
            $table->string('name', 120);
            $table->unsignedBigInteger('limit_minor');
            $table->unsignedBigInteger('committed_minor')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unique(['plan_id', 'code']);
        });

        Schema::create('workspace_budget_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('workspace_budget_plans');
            $table->string('kind', 12);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('previous_id')->nullable()->unique()->constrained('workspace_budget_documents');
            $table->foreignId('annual_version_id')->nullable()->constrained('workspace_budget_documents');
            $table->string('title', 160);
            $table->date('activity_date')->nullable();
            $table->string('status', 12)->default('draft');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['plan_id', 'kind', 'status']);
        });

        Schema::create('workspace_budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('workspace_budget_documents');
            $table->foreignId('item_id')->nullable()->constrained('workspace_budget_items');
            $table->string('code', 24)->nullable();
            $table->string('description', 120);
            $table->unsignedBigInteger('amount_minor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_budget_lines');
        Schema::dropIfExists('workspace_budget_documents');
        Schema::dropIfExists('workspace_budget_items');
        Schema::dropIfExists('workspace_budget_plans');
    }
};
