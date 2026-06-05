<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->string('event');
            $table->string('action')->nullable();
            $table->string('module')->nullable();
            $table->string('record_type')->nullable();
            $table->string('record_id')->nullable();
            $table->string('record_number')->nullable();

            // source link
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('source_number')->nullable();
            $table->unsignedInteger('source_revision')->nullable();
            $table->string('source_module')->nullable();
            $table->string('source_batch_id')->nullable();

            // revision ref
            $table->unsignedBigInteger('revision_id')->nullable();

            // user/company (central ids)
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();

            // result
            $table->string('result')->default('success');
            $table->text('message')->nullable();

            // values
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // metadata/context
            $table->json('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index('event');
            $table->index('action');
            $table->index('module');
            $table->index(['record_type', 'record_id']);
            $table->index('record_number');
            $table->index(['source_type', 'source_id']);
            $table->index('source_number');
            $table->index('source_module');
            $table->index('revision_id');
            $table->index('user_id');
            $table->index('company_id');
            $table->index('result');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_audit_logs');
    }
};

