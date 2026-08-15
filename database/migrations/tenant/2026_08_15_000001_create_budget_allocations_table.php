<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gap A — pagu top-down. `budget_submissions`/`budget_lines` adalah usulan
 * bottom-up yang MENJADI plafon begitu disetujui; tabel ini adalah plafon yang
 * ditetapkan SEBELUM usulan itu ada, dari atas ke bawah.
 *
 * Bentuknya pohon dua tingkat untuk saat ini (root perusahaan → departemen).
 * `parent_allocation_id` menunjuk ke diri sendiri secara generik supaya
 * skemanya tidak perlu dirombak kalau suatu hari tingkat lebih dalam
 * dibutuhkan, tapi service-nya SENGAJA menolak tingkat ketiga — proyek TIDAK
 * dapat pagu sendiri (Gap F: proyek tetap dimensi di `budget_lines`, bukan
 * penerima alokasi).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('budget_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('budget_period_id');
            // NULL = pagu tingkat perusahaan (root). Diisi = pagu departemen.
            $table->unsignedBigInteger('department_id')->nullable();
            // NULL = root. Diisi = menunjuk pagu induknya (saat ini selalu root).
            $table->unsignedBigInteger('parent_allocation_id')->nullable();
            $table->decimal('amount', 20, 2);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();

            $table->foreign('budget_period_id')->references('id')->on('budget_periods')->cascadeOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();
            $table->foreign('parent_allocation_id')->references('id')->on('budget_allocations')->cascadeOnDelete();

            $table->index('company_id');
            $table->index('budget_period_id');
            $table->index('department_id');
            $table->index('parent_allocation_id');
        });

        // NULL != NULL di SQL — tanpa ekspresi ini, dua pagu tingkat perusahaan
        // (department_id NULL) untuk periode yang sama bisa lolos begitu saja.
        // Pola yang sama dipakai `budget_lines_grain_unique`.
        DB::connection('tenant')->statement(
            'CREATE UNIQUE INDEX budget_allocations_period_department_unique ON budget_allocations '
            .'(budget_period_id, COALESCE(department_id, 0))'
        );
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('budget_allocations');
    }
};
