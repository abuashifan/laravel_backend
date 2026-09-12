<?php

namespace App\Providers;

use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
