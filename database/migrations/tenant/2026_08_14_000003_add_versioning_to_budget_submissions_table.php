<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `budget_submissions` menjadi dokumen BERVERSI (G2). Revisi tidak menimpa baris
 * lama: ia membuat submission baru dengan `version_no + 1` dan
 * `parent_submission_id` menunjuk pendahulunya, sehingga riwayat beserta
 * `budget_lines`-nya utuh.
 *
 * `revision_number` yang lama TIDAK dihapus — ia menghitung berapa kali sebuah
 * pengajuan ditolak lalu dikembalikan ke draft, beda peran dengan `version_no`
 * yang menghitung versi anggaran.
 *
 * `department_id` jadi nullable: NULL berarti anggaran tingkat perusahaan yang
 * diajukan Finance tanpa melewati tahap kepala departemen.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('budget_submissions', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_submission_id')->nullable()->after('budget_period_id');
            $table->unsignedSmallInteger('version_no')->default(1)->after('revision_number');
            $table->boolean('is_active')->default(false)->after('version_no');
            $table->text('revision_reason')->nullable()->after('rejection_note');
        });

        Schema::connection('tenant')->table('budget_submissions', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->change();
            $table->enum('status', ['draft', 'submitted', 'approved_by_head', 'approved', 'rejected', 'superseded'])
                ->default('draft')
                ->change();

            $table->foreign('budget_period_id')->references('id')->on('budget_periods')->cascadeOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();
            $table->foreign('parent_submission_id')->references('id')->on('budget_submissions')->nullOnDelete();

            $table->index(['budget_period_id', 'department_id', 'is_active']);
            $table->index('parent_submission_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('budget_submissions', function (Blueprint $table) {
            $table->dropForeign(['budget_period_id']);
            $table->dropForeign(['department_id']);
            $table->dropForeign(['parent_submission_id']);
            $table->dropIndex(['budget_period_id', 'department_id', 'is_active']);
            $table->dropIndex(['parent_submission_id']);
        });

        Schema::connection('tenant')->table('budget_submissions', function (Blueprint $table) {
            $table->enum('status', ['draft', 'submitted', 'approved_by_head', 'approved', 'rejected'])
                ->default('draft')
                ->change();
            $table->dropColumn(['parent_submission_id', 'version_no', 'is_active', 'revision_reason']);
        });
    }
};
