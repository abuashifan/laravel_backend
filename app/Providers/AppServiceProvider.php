<?php

namespace App\Providers;

use App\Contracts\Transactions\TransactionDateGuard;
use App\Contracts\Transactions\TransactionDependencyChecker;
use App\Services\Tenant\TenantContext;
use App\Services\Transactions\TransactionDependencyService;
use App\Services\Transactions\TransactionDateGuardService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, function () {
            return new TenantContext();
        });

        // Phase 4D placeholders (replaced by real implementations in Phase 4E/4F)
        $this->app->bind(TransactionDependencyChecker::class, TransactionDependencyService::class);
        $this->app->bind(TransactionDateGuard::class, TransactionDateGuardService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/central'));
    }
}
