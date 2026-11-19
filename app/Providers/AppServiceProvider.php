<?php

namespace App\Providers;

use App\Modules\CashBank\Services\CashBankReportService;
use App\Modules\Imports\Services\SpreadsheetReaderFactory;
use App\Shared\CashBank\CashBankBalanceReader;
use App\Shared\Export\TabularFileReader;
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
