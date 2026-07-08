<?php

namespace App\Shared\Providers;

use App\Shared\Tenant\TenantContext;
use App\Shared\TransactionLifecycle\Contracts\TransactionDateGuard;
use App\Shared\TransactionLifecycle\Contracts\TransactionDependencyChecker;
use App\Shared\TransactionLifecycle\TransactionDateGuardService;
use App\Shared\TransactionLifecycle\TransactionDependencyService;
use Illuminate\Support\ServiceProvider;

class SharedServiceProvider extends ServiceProvider
{
    /**
     * Register cross-cutting bindings (moved from AppServiceProvider in Fase 0).
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
     * Bootstrap shared services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/central'));
    }
}
