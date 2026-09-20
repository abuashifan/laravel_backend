<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 8: saldo awal bukan lagi dokumen tersendiri.
 *
 * Sejak `OpeningBalanceService` memposting jurnal langsung, `opening_balance_batches`
 * dan `opening_balance_lines` tidak punya penulis maupun pembaca. Baris saldo
 * awal kini hidup di `journal_entry_lines` seperti baris jurnal lain, dan
 * `fixed_assets.opening_balance_batch_id` menunjuk tabel yang sudah tidak ada.
 *
 * `down()` membangun ulang strukturnya, bukan isinya — data batch tidak bisa
 * diturunkan kembali dari jurnal yang menggantikannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('fixed_assets', 'opening_balance_batch_id')) {
            Schema::connection('tenant')->table('fixed_assets', function (Blueprint $table) {
                $table->dropConstrainedForeignId('opening_balance_batch_id');
            });
        }

        Schema::connection('tenant')->dropIfExists('opening_balance_lines');
        Schema::connection('tenant')->dropIfExists('opening_balance_batches');
    }

    public function down(): void
    {
        Schema::connection('tenant')->create('opening_balance_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 50)->unique();
            $table->date('opening_date');
            $table->unsignedSmallInteger('fiscal_year')->nullable();
            $table->string('type', 30)->default('standard');
            $table->string('status', 30)->default('draft');
            $table->text('description')->nullable();
            $table->decimal('total_debit', 20, 2)->default(0);
            $table->decimal('total_credit', 20, 2)->default(0);
            $table->decimal('difference', 20, 2)->default(0);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
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
            $table->index(['status', 'opening_date']);
        });

        Schema::connection('tenant')->create('opening_balance_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opening_balance_batch_id')->constrained('opening_balance_batches')->cascadeOnDelete();
            $table->unsignedBigInteger('account_id');
            $table->string('account_code', 30)->nullable();
            $table->string('account_name', 150)->nullable();
            $table->string('account_type', 30)->nullable();
            $table->decimal('debit', 20, 2)->default(0);
            $table->decimal('credit', 20, 2)->default(0);
            $table->text('description')->nullable();
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->boolean('is_system_generated')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['opening_balance_batch_id', 'is_system_generated']);
        });

        Schema::connection('tenant')->table('fixed_assets', function (Blueprint $table) {
            $table->foreignId('opening_balance_batch_id')
                ->nullable()
                ->after('source_type')
                ->constrained('opening_balance_batches')
                ->nullOnDelete();
        });
    }
};
