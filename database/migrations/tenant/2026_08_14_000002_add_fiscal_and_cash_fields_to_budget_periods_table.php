<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `fiscal_years` hidup di DB central sementara tabel ini di DB tenant, jadi
 * `fiscal_year_id` sengaja tanpa foreign key — pola yang sama dipakai
 * `journal_entries.created_by` / `posted_by`. Keterkaitannya divalidasi di service.
 *
 * `beginning_cash_override` hanya untuk Cash Budget: saldo awal normalnya
 * dihitung dari ledger, kolom ini dipakai kalau perlu ditimpa manual.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('budget_periods', function (Blueprint $table) {
            $table->unsignedBigInteger('fiscal_year_id')->nullable()->after('fiscal_year');
            $table->decimal('beginning_cash_override', 20, 2)->nullable()->after('status');
            $table->index(['fiscal_year', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('budget_periods', function (Blueprint $table) {
            $table->dropIndex(['fiscal_year', 'status']);
            $table->dropColumn(['fiscal_year_id', 'beginning_cash_override']);
        });
    }
};
