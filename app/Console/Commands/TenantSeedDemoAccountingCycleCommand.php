<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\TenantDatabase;
use App\Services\Tenant\TenantConnectionManager;
use Database\Seeders\tenant\TradingCompanyAccountingCycleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TenantSeedDemoAccountingCycleCommand extends Command
{
    protected $signature = 'tenant:seed-demo-accounting-cycle
        {--company-id= : Target company ID with an active tenant database}
        {--year=2025 : Demo fiscal year}
        {--reset-demo-data : Replace prior PT Nusantara Dagang Sejahtera demo data in the target tenant}
        {--close-year : Mark the seeded fiscal year closed and lock it after validation}
        {--force : Permit execution when APP_ENV=production}';

    protected $description = 'Seed a full-year trading company accounting cycle demo into one tenant database';

    public function handle(TenantConnectionManager $connections): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to seed demo data in production without --force.');
            return self::FAILURE;
        }

        $companyId = $this->option('company-id');
        if (! $companyId) {
            $this->error('The --company-id option is required.');
            return self::FAILURE;
        }

        $year = (int) $this->option('year');
        if ($year < 2000 || $year > 2100) {
            $this->error('The --year option must be between 2000 and 2100.');
            return self::FAILURE;
        }

        $company = Company::query()->find((int) $companyId);
        if (! $company) {
            $this->error('Target company not found.');
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
            $this->prepareCompanyAndFiscalPeriods($company, $year);
            $connections->connect($tenantDatabase);

            $result = (new TradingCompanyAccountingCycleSeeder())->seed(
                year: $year,
                resetDemoData: (bool) $this->option('reset-demo-data'),
            );

            if ((bool) $this->option('close-year')) {
                $this->closeYear($company, $year, (array) $result);
            }
        } catch (Throwable $e) {
            $this->error('Demo accounting cycle seed failed: '.$e->getMessage());
            return self::FAILURE;
        } finally {
            $connections->disconnect();
        }

        $trial = $result['trial_balance'];
        $balances = $result['balances'];

        $this->info('Trading company accounting cycle demo seeded successfully.');
        $this->line('Company: '.$company->id.' - PT Nusantara Dagang Sejahtera');
        $this->line('Tenant DB: '.$connections->resolveDatabasePath($tenantDatabase));
        $this->line('Year: '.$year.((bool) $this->option('close-year') ? ' (closed)' : ' (open)'));
        $this->line('Journal entries: '.$result['journal_entries']);
        $this->line(sprintf('Trial balance: debit %.2f / credit %.2f / balanced %s / unbalanced journals %d',
            $trial['debit'],
            $trial['credit'],
            $trial['balanced'] ? 'YES' : 'NO',
            $trial['unbalanced_journals'],
        ));
        $this->line(sprintf('Ending balances: cash %.2f / bank %.2f / AR %.2f / AP %.2f / inventory %.2f',
            $balances['cash'],
            $balances['bank'],
            $balances['accounts_receivable'],
            $balances['accounts_payable'],
            $balances['inventory'],
        ));
        $this->line('Seeded tables: '.implode(', ', $result['seeded_tables']));
        $this->line('Skipped tables: '.($result['skipped_tables'] === [] ? 'none' : implode(', ', $result['skipped_tables'])));

        return $trial['balanced'] && (int) $trial['unbalanced_journals'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function prepareCompanyAndFiscalPeriods(Company $company, int $year): void
    {
        $company->forceFill([
            'name' => 'PT Nusantara Dagang Sejahtera',
            'legal_name' => 'PT Nusantara Dagang Sejahtera',
            'slug' => 'pt-nusantara-dagang-sejahtera',
            'email' => 'finance@nusantara-dagang.test',
            'phone' => '021-5088-2025',
            'address' => 'Jl. Perdagangan Nusantara No. 25',
            'city' => 'Jakarta',
            'province' => 'DKI Jakarta',
            'country' => 'Indonesia',
            'business_type' => 'trading',
            'status' => 'active',
        ])->save();

        if (! Schema::hasTable('fiscal_years') || ! Schema::hasTable('accounting_periods')) {
            $this->warn('Central fiscal_years/accounting_periods tables not present; fiscal period setup skipped.');
            return;
        }

        $existing = DB::table('fiscal_years')->where(['company_id' => $company->id, 'year' => $year])->first();
        if ($existing && (($existing->status ?? null) === 'closed' || (bool) ($existing->is_closed ?? false))) {
            throw new \RuntimeException("Fiscal year {$year} is closed for the target company.");
        }

        DB::table('fiscal_years')->updateOrInsert(
            ['company_id' => $company->id, 'year' => $year],
            [
                'start_date' => $year.'-01-01',
                'end_date' => $year.'-12-31',
                'status' => 'open',
                'is_active' => true,
                'is_closed' => false,
                'locked_until' => null,
                'metadata' => json_encode(['seeded_by' => 'trading_company_accounting_cycle_2025']),
                'updated_at' => now(),
                'created_at' => $existing->created_at ?? now(),
            ]
        );

        $fiscalYearId = (int) DB::table('fiscal_years')->where(['company_id' => $company->id, 'year' => $year])->value('id');
        for ($month = 1; $month <= 12; $month++) {
            $start = Carbon::create($year, $month, 1)->startOfMonth();
            DB::table('accounting_periods')->updateOrInsert(
                ['company_id' => $company->id, 'period_year' => $year, 'period_month' => $month],
                [
                    'fiscal_year_id' => $fiscalYearId,
                    'start_date' => $start->toDateString(),
                    'end_date' => $start->copy()->endOfMonth()->toDateString(),
                    'status' => 'open',
                    'closed_at' => null,
                    'closed_by' => null,
                    'metadata' => json_encode(['seeded_by' => 'trading_company_accounting_cycle_2025']),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    private function closeYear(Company $company, int $year, array $seedResult): void
    {
        if (! ($seedResult['trial_balance']['balanced'] ?? false)) {
            throw new \RuntimeException('Refusing to close demo fiscal year because trial balance is not balanced.');
        }

        if (! Schema::hasTable('fiscal_years')) {
            throw new \RuntimeException('Cannot close demo fiscal year because fiscal_years table is not available.');
        }

        $fiscalYear = DB::table('fiscal_years')->where(['company_id' => $company->id, 'year' => $year])->first();
        if (! $fiscalYear) {
            throw new \RuntimeException('Cannot close demo fiscal year because fiscal year record was not found.');
        }

        if (Schema::connection('tenant')->hasTable('fiscal_year_closings')) {
            DB::connection('tenant')->table('fiscal_year_closings')->updateOrInsert(
                ['fiscal_year_id' => $fiscalYear->id],
                [
                    'closed_by_user_id' => null,
                    'retained_earnings_account_id' => DB::connection('tenant')->table('account_mappings')->where('mapping_key', 'closing.retained_earnings')->value('account_id'),
                    'retained_earnings_amount' => 0,
                    'closing_notes' => 'Optional demo close-year flag executed by tenant:seed-demo-accounting-cycle.',
                    'closed_at' => now(),
                    'status' => 'completed',
                    'metadata' => json_encode(['seeded_by' => 'trading_company_accounting_cycle_2025']),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        DB::table('accounting_periods')
            ->where('company_id', $company->id)
            ->where('period_year', $year)
            ->update([
                'status' => 'closed',
                'closed_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('fiscal_years')->where('id', $fiscalYear->id)->update([
            'status' => 'closed',
            'is_active' => false,
            'is_closed' => true,
            'closed_at' => now(),
            'locked_until' => $year.'-12-31',
            'updated_at' => now(),
        ]);
    }
}
