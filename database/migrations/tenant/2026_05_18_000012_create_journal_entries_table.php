<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('journal_number')->unique();
            $table->date('journal_date');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('revision_no')->default(1);

            // Source link fields
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('source_number')->nullable();
            $table->unsignedInteger('source_revision')->nullable();
            $table->string('source_module')->nullable();
            $table->string('source_batch_id')->nullable();
            $table->boolean('is_system_generated')->default(false);
            $table->boolean('is_obsolete')->default(false);

            // Audit/user fields (central user IDs; no cross-db FK)
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->text('edit_reason')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('journal_date');
            $table->index('status');
            $table->index('revision_no');
            $table->index(['source_type', 'source_id']);
            $table->index('source_number');
            $table->index('source_module');
            $table->index('source_revision');
            $table->index('is_system_generated');
            $table->index('is_obsolete');
            $table->index('posted_at');
            $table->index('voided_at');
            $table->index('created_by');
            $table->index('posted_by');
            $table->index('voided_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};

