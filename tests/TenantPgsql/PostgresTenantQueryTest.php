<?php

namespace Tests\TenantPgsql;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Services\ChartOfAccountService;
use App\Modules\Reports\Services\TrialBalanceService;
use App\Shared\Reports\Data\TrialBalanceFilter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Query aplikasi yang memakai SQL mentah, dieksekusi Postgres sungguhan.
 *
 * Migration yang portabel belum menjamin apa pun soal query: daftar akun
 * memakai CTE rekursif dengan `char(1)` -- fungsi SQLite yang di Postgres
 * adalah nama tipe data -- sehingga SELURUH halaman Chart of Account gagal di
 * tenant Postgres sementara seluruh suite SQLite tetap hijau. Di layar itu
 * terbaca seolah template COA tidak pernah terpasang.
 */
class PostgresTenantQueryTest extends PostgresTenantTestCase
{
    public function test_chart_of_account_hierarchy_list_runs_on_postgres(): void
    {
        $storage = $this->storage();
        $schema = $this->uniqueSchema('coalist');

        $storage->create($schema, $schema);
        $storage->connect($schema, $schema);

        $exitCode = Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
        $this->assertSame(0, $exitCode, 'Migration tenant gagal: '.Artisan::output());

        $induk = ChartOfAccount::query()->create([
            'account_code' => '1',
            'account_name' => 'AKTIVA LANCAR',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
        ChartOfAccount::query()->create([
            'account_code' => '1100',
            'account_name' => 'Kas',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'parent_account_id' => $induk->id,
            'is_active' => true,
        ]);

        $rows = app(ChartOfAccountService::class)->list(['per_page' => 25]);

        $this->assertCount(2, $rows->items());
        $this->assertSame('1', $rows->items()[0]->account_code);
        $this->assertSame(0, (int) $rows->items()[0]->depth);
        $this->assertSame('1100', $rows->items()[1]->account_code);
        $this->assertSame(1, (int) $rows->items()[1]->depth, 'Anak harus terbaca satu tingkat di bawah induknya.');
    }

    /**
     * Finalisasi setup mengunci aset hasil import lewat metadata. Versi lamanya
     * memakai `json_set()` di SQL -- juga khusus SQLite -- dan menggagalkan
     * tombol terakhir wizard dengan 500 di setiap tenant Postgres.
     */
    public function test_locking_opening_assets_metadata_runs_on_postgres(): void
    {
        $storage = $this->storage();
        $schema = $this->uniqueSchema('lockmeta');

        $storage->create($schema, $schema);
        $storage->connect($schema, $schema);

        DB::connection('tenant')->statement('CREATE TABLE fixed_assets (id serial primary key, source_type text, metadata json, updated_at timestamp)');
        DB::connection('tenant')->table('fixed_assets')->insert([
            ['source_type' => 'opening_import', 'metadata' => json_encode(['import_row' => 3])],
            ['source_type' => null, 'metadata' => null],
        ]);

        DB::connection('tenant')->table('fixed_assets')
            ->where('source_type', 'opening_import')
            ->orderBy('id')
            ->get(['id', 'metadata'])
            ->each(function (object $asset): void {
                $metadata = is_string($asset->metadata) ? json_decode($asset->metadata, true) : null;
                $metadata = is_array($metadata) ? $metadata : [];
                $metadata['setup_locked'] = true;

                DB::connection('tenant')->table('fixed_assets')->where('id', $asset->id)->update([
                    'metadata' => json_encode($metadata),
                    'updated_at' => now(),
                ]);
            });

        $locked = DB::connection('tenant')->table('fixed_assets')->where('source_type', 'opening_import')->value('metadata');
        $untouched = DB::connection('tenant')->table('fixed_assets')->whereNull('source_type')->value('metadata');

        $this->assertSame(['import_row' => 3, 'setup_locked' => true], json_decode((string) $locked, true));
        $this->assertNull($untouched);
    }

    /**
     * Neraca Saldo menyaring tanggal jurnal dengan `substr(je.journal_date, 1, 10)`.
     * `journal_date` bertipe `date`, dan `substr(date, int, int)` tidak ada di
     * Postgres, jadi laporan itu 500 di setiap tenant Postgres (halaman memunculkan
     * "Gagal memuat laporan") sementara suite SQLite tetap hijau. Laporan lain sudah
     * memakai `whereDate()`.
     */
    public function test_trial_balance_runs_on_postgres(): void
    {
        $storage = $this->storage();
        $schema = $this->uniqueSchema('trialbal');
        $storage->create($schema, $schema);
        $storage->connect($schema, $schema);
        $exitCode = Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]);
        $this->assertSame(0, $exitCode, 'Migration tenant gagal: '.Artisan::output());

        $cash = ChartOfAccount::query()->create(['account_code' => '1100', 'account_name' => 'Kas', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_active' => true]);
        $equity = ChartOfAccount::query()->create(['account_code' => '3100', 'account_name' => 'Modal', 'account_type' => 'equity', 'normal_balance' => 'credit', 'is_active' => true]);

        // Saldo awal sebelum periode, dan satu jurnal di dalam periode.
        foreach ([['2025-12-31', 'JV-OPEN', 1000], ['2026-03-10', 'JV-IN', 250]] as [$date, $number, $amount]) {
            $journal = JournalEntry::query()->create([
                'journal_number' => $number, 'journal_date' => $date, 'description' => $number, 'status' => 'posted',
                'revision_no' => 1, 'is_obsolete' => false, 'is_system_generated' => false,
            ]);
            $journal->lines()->createMany([
                ['account_id' => $cash->id, 'debit' => $amount, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $equity->id, 'debit' => 0, 'credit' => $amount, 'line_order' => 2],
            ]);
        }

        $result = app(TrialBalanceService::class)->getTrialBalance(new TrialBalanceFilter(startDate: '2026-01-01', endDate: '2026-11-14'));

        $this->assertTrue($result['valid'], json_encode($result));
        $rows = collect($result['accounts'])->keyBy('account_code');
        $this->assertEquals(1000.0, (float) $rows['1100']['opening_debit']);
        $this->assertEquals(250.0, (float) $rows['1100']['period_debit']);
        $this->assertEquals(1250.0, (float) $rows['1100']['ending_debit']);
        $this->assertEquals(1250.0, (float) $rows['3100']['ending_credit']);
    }
}
