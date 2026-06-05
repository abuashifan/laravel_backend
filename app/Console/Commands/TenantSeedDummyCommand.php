<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\TenantDatabase;
use App\Services\Tenant\TenantConnectionManager;
use Database\Seeders\tenant\TenantDummyDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TenantSeedDummyCommand extends Command
{
    protected $signature = 'tenant:seed-dummy
        {company_id? : Target company ID with an active tenant database}
        {--period=2026-01 : Dummy accounting period in YYYY-MM format}
        {--force : Permit execution when APP_ENV=production}';

    protected $description = 'Seed a compact one-month full accounting cycle into one tenant database';

    public function handle(TenantConnectionManager $connections): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to seed dummy data in production without --force.');
            return self::FAILURE;
        }

        $period = (string) $this->option('period');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $this->error('Period must use YYYY-MM format, for example 2026-01.');
            return self::FAILURE;
        }

        $companyId = $this->argument('company_id');
        $company = $companyId
            ? Company::query()->find((int) $companyId)
            : Company::query()->whereIn('status', ['active', 'trial'])->orderBy('id')->first();

        if (! $company) {
            $this->error('Target company not found. Provide a valid company_id.');
            return self::FAILURE;
        }

        $tenantDatabase = TenantDatabase::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->first();
        if (! $tenantDatabase) {
            $this->error('Active tenant database not found for company '.$company->id.'.');
            return self::FAILURE;
        }

        try {
            $this->ensureOpenPeriod((int) $company->id, $period);
            $connections->connect($tenantDatabase);
            $result = (new TenantDummyDataSeeder())->seed($period);
        } catch (Throwable $e) {
            $this->error('Dummy tenant seed failed: '.$e->getMessage());
            return self::FAILURE;
        } finally {
            $connections->disconnect();
        }

        $trial = $result['trial_balance'];
        $this->info('Tenant dummy accounting cycle seeded successfully.');
        $this->line('Company: '.$company->id.' - '.$company->name);
        $this->line('Tenant DB: '.$connections->resolveDatabasePath($tenantDatabase));
        $this->line('Period: '.$period.' (open)');
        $this->line('Journal entries: '.$result['journal_entries']);
        $this->line(sprintf('Trial balance: debit %.2f / credit %.2f / balanced %s', $trial['debit'], $trial['credit'], $trial['balanced'] ? 'YES' : 'NO'));
        $this->newLine();
        $this->line('Seeded tables: '.implode(', ', $result['seeded_tables']));
        $this->line('Skipped tables: '.($result['skipped_tables'] === [] ? 'none' : implode(', ', $result['skipped_tables'])));

        return $trial['balanced'] ? self::SUCCESS : self::FAILURE;
    }

    private function ensureOpenPeriod(int $companyId, string $period): void
    {
        $start = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        $year = (int) $start->format('Y');

        if (! Schema::hasTable('fiscal_years') || ! Schema::hasTable('accounting_periods')) {
            $this->warn('Central fiscal_years/accounting_periods tables not present; period setup skipped.');
            return;
        }

        $fiscalYear = DB::table('fiscal_years')->where(['company_id' => $companyId, 'year' => $year])->first();
        if ($fiscalYear && ($fiscalYear->status === 'closed' || (bool) ($fiscalYear->is_closed ?? false))) {
            throw new \RuntimeException("Fiscal year {$year} is closed for the target company.");
        }

        DB::table('fiscal_years')->updateOrInsert(
            ['company_id' => $companyId, 'year' => $year],
            [
                'start_date' => $year.'-01-01', 'end_date' => $year.'-12-31', 'status' => 'open',
                'is_active' => true, 'is_closed' => false, 'metadata' => json_encode(['seeded_by' => 'tenant_dummy_full_cycle_january_2026']),
                'updated_at' => now(), 'created_at' => $fiscalYear->created_at ?? now(),
            ]
        );
        $fiscalYearId = (int) DB::table('fiscal_years')->where(['company_id' => $companyId, 'year' => $year])->value('id');
        DB::table('accounting_periods')->updateOrInsert(
            ['company_id' => $companyId, 'period_year' => $year, 'period_month' => (int) $start->format('n')],
            [
                'fiscal_year_id' => $fiscalYearId, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
                'status' => 'open', 'closed_at' => null, 'closed_by' => null,
                'metadata' => json_encode(['seeded_by' => 'tenant_dummy_full_cycle_january_2026']),
                'updated_at' => now(), 'created_at' => now(),
            ]
        );
    }
}
