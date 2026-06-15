<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balance_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number')->unique();
            $table->date('opening_date');
            $table->unsignedSmallInteger('fiscal_year')->nullable();
            $table->string('type', 40)->default('standard');
            $table->string('status', 30)->default('draft');
            $table->text('description')->nullable();
            $table->decimal('total_debit', 18, 2)->default(0);
            $table->decimal('total_credit', 18, 2)->default(0);
            $table->decimal('difference', 18, 2)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->unsignedBigInteger('validated_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['opening_date', 'status']);
            $table->index('fiscal_year');
            $table->index('journal_entry_id');
        });

        Schema::create('opening_balance_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opening_balance_batch_id')->constrained('opening_balance_batches')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('account_code')->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_type', 40)->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->text('description')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->boolean('is_system_generated')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['opening_balance_batch_id', 'is_system_generated']);
            $table->index(['source_type', 'source_id', 'source_line_id']);
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_lines');
        Schema::dropIfExists('opening_balance_batches');
    }
};
