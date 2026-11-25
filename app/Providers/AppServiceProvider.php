<?php

namespace App\Providers;

use App\Modules\Budget\Services\AccountingBudgetPeriodReader;
use App\Modules\CashBank\Services\CashBankReportService;
use App\Modules\CashBank\Services\WorkspaceActivityAccountingGateway;
use App\Modules\Imports\Services\SpreadsheetReaderFactory;
use App\Modules\Workspace\Services\WorkspaceBudgetSummary;
use App\Shared\Budget\BudgetPeriodOverlapReader;
use App\Shared\Budget\WorkspaceBudgetSummaryReader;
use App\Shared\CashBank\CashBankBalanceReader;
use App\Shared\Export\TabularFileReader;
use App\Shared\Workspace\ActivityAccountingGateway;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TabularFileReader::class, SpreadsheetReaderFactory::class);
        $this->app->bind(CashBankBalanceReader::class, CashBankReportService::class);
        $this->app->bind(BudgetPeriodOverlapReader::class, AccountingBudgetPeriodReader::class);
        $this->app->bind(WorkspaceBudgetSummaryReader::class, WorkspaceBudgetSummary::class);
        $this->app->bind(ActivityAccountingGateway::class, WorkspaceActivityAccountingGateway::class);
        // Cross-cutting bindings moved to App\Shared\Providers\SharedServiceProvider (Fase 0).
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(UrlGenerator $url): void
    {
        // Central migrations loading moved to SharedServiceProvider::boot() (Fase 0).

        if ($this->app->environment('production')) {
            $url->forceScheme('https');
        }
    }
}
