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

    /** Daftar status setelah migrasi ini — bertambah `superseded`. */
    private const STATUSES = ['draft', 'submitted', 'approved_by_head', 'approved', 'rejected', 'superseded'];

    /** Daftar status sebelum migrasi ini, dipakai `down()`. */
    private const PREVIOUS_STATUSES = ['draft', 'submitted', 'approved_by_head', 'approved', 'rejected'];

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
        });

        $this->setStatusValues(self::STATUSES);

        Schema::connection('tenant')->table('budget_submissions', function (Blueprint $table) {
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

        $this->setStatusValues(self::PREVIOUS_STATUSES);

        Schema::connection('tenant')->table('budget_submissions', function (Blueprint $table) {
            $table->dropColumn(['parent_submission_id', 'version_no', 'is_active', 'revision_reason']);
        });
    }

    /**
     * Ganti daftar nilai yang boleh diisi kolom `status`.
     *
     * TIDAK memakai `$table->enum(...)->change()`. Di Postgres, `enum()` bukan
     * tipe tersendiri melainkan `varchar` + CHECK constraint terpisah, dan
     * Laravel menempelkan `check (...)` langsung ke `ALTER COLUMN ... TYPE` —
     * yang bukan sintaks sah Postgres dan gagal dengan SQLSTATE 42601. Gejalanya
     * tidak pernah muncul di SQLite karena di sana Laravel membangun ulang
     * seluruh tabel, jadi bug ini lolos dari suite tes sampai tenant benar-benar
     * dijalankan di atas Postgres.
     *
     * @param  list<string>  $statuses
     */
    private function setStatusValues(array $statuses): void
    {
        $connection = Schema::connection('tenant')->getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            Schema::connection('tenant')->table('budget_submissions', function (Blueprint $table) use ($statuses) {
                $table->enum('status', $statuses)->default('draft')->change();
            });

            return;
        }

        // Nama constraint dicari dari katalog, bukan ditebak dari konvensi
        // `{tabel}_{kolom}_check`: constraint yang tertinggal akan menolak nilai
        // baru tanpa jejak yang jelas, dan itu jauh lebih sulit dilacak daripada
        // query tambahan di sini.
        $connection->statement(<<<'SQL'
            DO $$
            DECLARE c text;
            BEGIN
              FOR c IN
                SELECT con.conname
                  FROM pg_constraint con
                  JOIN pg_class rel ON rel.oid = con.conrelid
                  JOIN pg_namespace ns ON ns.oid = rel.relnamespace
                 WHERE rel.relname = 'budget_submissions'
                   AND ns.nspname = current_schema()
                   AND con.contype = 'c'
                   AND pg_get_constraintdef(con.oid) ILIKE '%status%'
              LOOP
                EXECUTE format('ALTER TABLE budget_submissions DROP CONSTRAINT %I', c);
              END LOOP;
            END $$;
        SQL);

        $list = implode(', ', array_map(
            static fn (string $status): string => "'".str_replace("'", "''", $status)."'",
            $statuses,
        ));

        $connection->statement(
            'ALTER TABLE budget_submissions ADD CONSTRAINT budget_submissions_status_check '
            ."CHECK (status IN ({$list}))"
        );

        $connection->statement("ALTER TABLE budget_submissions ALTER COLUMN status SET DEFAULT 'draft'");
    }
};
