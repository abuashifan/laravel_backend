<?php

namespace Tests\TenantPgsql;

use App\Shared\Database\DatePeriodExpression;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Seluruh migration tenant dijalankan di Postgres sungguhan.
 *
 * Di suite SQLite, 100 migration itu memang lulus — tapi lewat grammar SQLite.
 * Test ini yang membuktikan skema yang sama bisa dibangun di Postgres, satu-
 * satunya cara mengetahui ada migration yang tidak portabel sebelum deploy.
 */
class PostgresTenantMigrationTest extends PostgresTenantTestCase
{
    public function test_all_tenant_migrations_run_on_postgres(): void
    {
        $storage = $this->storage();
        $schema = $this->uniqueSchema('migrate');

        $storage->create($schema, $schema);
        $storage->connect($schema, $schema);

        $exitCode = Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, 'Migration tenant gagal di Postgres: '.Artisan::output());

        $tables = DB::connection($this->connection)
            ->table('information_schema.tables')
            ->where('table_schema', $schema)
            ->pluck('table_name')
            ->all();

        // Tabel inti akuntansi; kalau salah satu hilang, migration berhenti di tengah.
        foreach (['chart_of_accounts', 'journals', 'journal_lines', 'migrations'] as $expected) {
            $this->assertContains($expected, $tables, "Tabel {$expected} tidak terbentuk di schema tenant.");
        }
    }

    /**
     * Ekspresi pengelompokan periode benar-benar dieksekusi Postgres.
     * `DATE_FORMAT()` — cabang default sebelum ada `DatePeriodExpression` —
     * tidak ada di Postgres dan akan gagal di sini.
     */
    public function test_period_expression_executes_on_postgres(): void
    {
        $storage = $this->storage();
        $schema = $this->uniqueSchema('period');

        $storage->create($schema, $schema);
        $storage->connect($schema, $schema);

        DB::connection('tenant')->statement('CREATE TABLE bills (bill_date date)');
        DB::connection('tenant')->table('bills')->insert([
            ['bill_date' => '2026-03-05'],
            ['bill_date' => '2026-03-19'],
            ['bill_date' => '2026-04-02'],
        ]);

        [$selectExpr, $groupExpr] = DatePeriodExpression::for('pgsql', 'bill_date', 'month');

        $rows = DB::connection('tenant')
            ->table('bills')
            ->selectRaw($selectExpr.', COUNT(*) as total')
            ->groupByRaw($groupExpr)
            ->orderByRaw($groupExpr)
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('2026-03', $rows[0]->period);
        $this->assertSame(2, (int) $rows[0]->total);
        $this->assertSame('2026-04', $rows[1]->period);

        [$daySelect, $dayGroup] = DatePeriodExpression::for('pgsql', 'bill_date', 'day');

        $daily = DB::connection('tenant')
            ->table('bills')
            ->selectRaw($daySelect.', COUNT(*) as total')
            ->groupByRaw($dayGroup)
            ->orderByRaw($dayGroup)
            ->get();

        $this->assertCount(3, $daily);
        $this->assertSame('2026-03-05', $daily[0]->period);
    }
}
