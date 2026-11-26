<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fase 5: penyelesaian RAB Kegiatan melepas sisa komitmen per pos. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_budget_documents', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('close_note')->nullable();
        });

        Schema::create('workspace_budget_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('workspace_budget_documents');
            $table->foreignId('item_id')->constrained('workspace_budget_items');
            $table->unsignedBigInteger('approved_minor');
            $table->unsignedBigInteger('realized_minor');
            $table->unsignedBigInteger('released_minor');
            $table->unsignedBigInteger('created_by');
            $table->timestamp('created_at');
            $table->unique(['document_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_budget_releases');
        Schema::table('workspace_budget_documents', fn (Blueprint $table) => $table->dropColumn(['closed_at', 'closed_by', 'close_note']));
    }
};
