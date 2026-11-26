<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 Workspace: satu RAB Kegiatan dapat dicairkan dalam beberapa tahap,
 * berupa biaya langsung atau dana muka yang kemudian diselesaikan.
 * Pencairan Fase 3 (satu per kegiatan) dipindahkan menjadi tahap pertama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_disbursement_tranches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_disbursement_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('kind', 12)->default('direct');
            $table->string('status', 12)->default('active');
            $table->unsignedBigInteger('amount_minor');
            $table->json('lines');
            $table->date('disbursed_on');
            $table->unsignedBigInteger('cash_bank_account_id');
            $table->string('payment_reference', 160);
            $table->unsignedBigInteger('recorded_by');
            $table->timestamp('recorded_at');
            $table->string('qc_status', 12)->default('pending');
            $table->unsignedBigInteger('qc_by')->nullable();
            $table->timestamp('qc_at')->nullable();
            $table->text('qc_note')->nullable();
            $table->unsignedBigInteger('advance_account_id')->nullable();
            $table->foreignId('cash_payment_id')->nullable()->unique()->constrained('cash_payments');
            $table->date('settled_on')->nullable();
            $table->json('settlement_lines')->nullable();
            $table->unsignedBigInteger('refund_minor')->default(0);
            $table->unsignedBigInteger('refund_cash_bank_account_id')->nullable();
            $table->foreignId('settlement_journal_id')->nullable()->unique()->constrained('journal_entries');
            $table->unsignedBigInteger('settled_by')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_disbursement_id', 'sequence']);
        });

        Schema::table('workspace_disbursements', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
        });
        Schema::table('workspace_disbursement_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('tranche_id')->nullable();
        });

        DB::table('workspace_disbursements')->whereNotNull('disbursed_on')->orderBy('id')->each(function (object $row): void {
            $lines = collect(json_decode($row->source_lines, true, 512, JSON_THROW_ON_ERROR))
                ->map(fn (array $line) => ['source_line_id' => (int) $line['id'], 'amount_minor' => (int) $line['amount_minor']])->all();
            $tranche = DB::table('workspace_disbursement_tranches')->insertGetId([
                'workspace_disbursement_id' => $row->id, 'sequence' => 1, 'kind' => 'direct', 'status' => 'active',
                'amount_minor' => $row->amount_minor, 'lines' => json_encode($lines, JSON_THROW_ON_ERROR),
                'disbursed_on' => $row->disbursed_on, 'cash_bank_account_id' => $row->cash_bank_account_id,
                'payment_reference' => (string) $row->payment_reference, 'recorded_by' => (int) $row->disbursed_by,
                'recorded_at' => $row->disbursed_at ?? $row->updated_at, 'qc_status' => $row->qc_status,
                'qc_by' => $row->qc_by, 'qc_at' => $row->qc_at, 'qc_note' => $row->qc_note,
                'cash_payment_id' => $row->cash_payment_id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('workspace_disbursement_events')->where('workspace_disbursement_id', $row->id)->update(['tranche_id' => $tranche]);
            DB::table('workspace_disbursements')->where('id', $row->id)->update(['cash_payment_id' => null]);
        });
    }

    public function down(): void
    {
        Schema::table('workspace_disbursement_events', fn (Blueprint $table) => $table->dropColumn('tranche_id'));
        Schema::table('workspace_disbursements', fn (Blueprint $table) => $table->dropColumn(['closed_at', 'closed_by']));
        Schema::dropIfExists('workspace_disbursement_tranches');
    }
};
