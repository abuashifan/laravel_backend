<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_disbursements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_document_id')->unique();
            $table->unsignedBigInteger('workspace_annual_version_id');
            $table->unsignedBigInteger('workspace_period_id');
            $table->unsignedBigInteger('workspace_unit_id');
            $table->string('unit_name', 120);
            $table->unsignedInteger('source_version');
            $table->string('title', 160);
            $table->date('activity_date');
            $table->unsignedBigInteger('approved_by');
            $table->timestamp('approved_at');
            $table->unsignedBigInteger('amount_minor');
            $table->json('source_lines');
            $table->date('disbursed_on')->nullable();
            $table->unsignedBigInteger('disbursed_by')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->unsignedBigInteger('cash_bank_account_id')->nullable();
            $table->string('payment_reference', 160)->nullable();
            $table->string('qc_status', 12)->default('pending')->index();
            $table->unsignedBigInteger('qc_by')->nullable();
            $table->timestamp('qc_at')->nullable();
            $table->text('qc_note')->nullable();
            $table->foreignId('cash_payment_id')->nullable()->unique()->constrained('cash_payments');
            $table->timestamps();
        });

        Schema::create('workspace_disbursement_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_disbursement_id')->constrained()->cascadeOnDelete();
            $table->string('action', 32);
            $table->unsignedBigInteger('user_id');
            $table->timestamp('created_at');
            $table->json('details');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_disbursement_events');
        Schema::dropIfExists('workspace_disbursements');
    }
};
