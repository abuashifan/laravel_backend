<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('source_type');
            $table->string('source_id')->nullable();
            $table->string('source_number')->nullable();
            $table->string('source_module')->nullable();
            $table->unsignedInteger('source_revision_from')->nullable();
            $table->unsignedInteger('source_revision_to')->nullable();
            $table->string('action');
            $table->text('reason')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('changed_fields')->nullable();
            $table->unsignedBigInteger('edited_by')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index('source_number');
            $table->index('source_module');
            $table->index('source_revision_to');
            $table->index('action');
            $table->index('edited_by');
            $table->index('edited_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_revisions');
    }
};

