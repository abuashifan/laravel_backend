<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grain `budget_lines` berubah (G1, G3): satu baris = satu nominal untuk satu
 * kombinasi (submission, akun, cost center, proyek, bulan). Karena grain-nya
 * berubah, tabel lama dibuang dan dibuat ulang — bukan di-alter.
 *
 * Tabel lama tidak punya satu pun foreign key dan tidak punya unique constraint,
 * jadi baris yatim dan duplikat bisa lolos begitu saja.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $this->assertTableIsEmpty();

        Schema::connection('tenant')->dropIfExists('budget_lines');

        Schema::connection('tenant')->create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_submission_id');
            $table->unsignedBigInteger('account_id');
            // NULL = belum dialokasikan ke cost center mana pun.
            $table->unsignedBigInteger('department_id')->nullable();
            // NULL = lintas proyek / bukan anggaran proyek.
            $table->unsignedBigInteger('project_id')->nullable();
            // 'YYYY-MM'; NULL = anggaran tahunan.
            $table->char('period_month', 7)->nullable();
            // Diturunkan dari chart_of_accounts.account_type saat baris ditulis,
            // bukan diinput user. Disimpan (bukan di-join tiap kali) supaya filter
            // direction=revenue bisa memakai index.
            $table->string('direction', 10);
            $table->decimal('amount', 20, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('budget_submission_id')->references('id')->on('budget_submissions')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();

            $table->index(['budget_submission_id', 'account_id']);
            $table->index('account_id');
            $table->index('department_id');
            $table->index('project_id');
            $table->index('period_month');
            $table->index('direction');
        });

        // NULL != NULL di SQL, jadi unique biasa tidak menggigit untuk baris yang
        // dimensinya kosong — itulah sebabnya migration lama menyerahkannya ke
        // service. Unique berbasis ekspresi menutup celah itu; SQLite dan MySQL 8
        // sama-sama mendukungnya.
        DB::connection('tenant')->statement(
            'CREATE UNIQUE INDEX budget_lines_grain_unique ON budget_lines ('
            .'budget_submission_id, account_id, '
            ."COALESCE(department_id, 0), COALESCE(project_id, 0), COALESCE(period_month, ''))"
        );
    }

    public function down(): void
    {
        $this->assertTableIsEmpty();

        Schema::connection('tenant')->dropIfExists('budget_lines');

        Schema::connection('tenant')->create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_submission_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->char('period', 7)->nullable();
            $table->decimal('amount', 20, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('budget_submission_id');
            $table->index('account_id');
        });
    }

    /**
     * Tenant yang terlanjur mengisi anggaran tidak boleh kehilangan datanya diam-diam.
     * Saat rencana ini disusun kedua tenant berisi 0 baris, tapi itu diperiksa di
     * runtime — bukan diandalkan.
     */
    private function assertTableIsEmpty(): void
    {
        if (! Schema::connection('tenant')->hasTable('budget_lines')) {
            return;
        }

        $count = DB::connection('tenant')->table('budget_lines')->count();

        if ($count > 0) {
            throw new RuntimeException(
                "Migration dibatalkan: tabel `budget_lines` berisi {$count} baris. "
                .'Grain tabel ini berubah sehingga tabel harus dibuat ulang, dan migration '
                .'ini sengaja menolak menghapus data anggaran yang sudah ada. '
                .'Pindahkan/ekspor datanya lebih dulu, baru jalankan ulang.'
            );
        }
    }
};
