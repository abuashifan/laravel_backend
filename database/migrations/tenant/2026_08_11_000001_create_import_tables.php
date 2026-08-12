<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('profile');
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('file_hash', 64);
            $table->json('column_map')->nullable();
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedInteger('committed_rows')->default(0);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['profile', 'file_hash']);
            $table->index('status');
            $table->index('created_by');
        });

        Schema::connection('tenant')->create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->string('profile');
            $table->unsignedInteger('row_number');
            $table->json('raw');
            $table->json('normalized')->nullable();
            $table->string('status', 30)->default('pending');
            $table->json('errors')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('document_type')->nullable();
            $table->string('external_ref')->nullable();
            $table->timestamps();

            $table->unique(['import_batch_id', 'row_number']);
            $table->index(['profile', 'external_ref']);
            $table->index(['status', 'row_number']);
            $table->index(['document_type', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('import_rows');
        Schema::connection('tenant')->dropIfExists('import_batches');
    }
};
