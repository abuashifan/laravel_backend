<?php

namespace Tests\Feature\Architecture;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ModuleRoutesTest extends TestCase
{
    public function test_api_module_routes_are_registered(): void
    {
        $this->assertRouteExists('GET', 'health');
        $this->assertRouteExists('POST', 'auth/login');
        $this->assertRouteExists('GET', 'companies', ['auth:sanctum']);
        $this->assertRouteExists('GET', 'tenant-context-test', ['auth:sanctum', 'company.access']);
        $this->assertRouteExists('GET', 'master-data/products', ['auth:sanctum', 'company.access', 'permission:products.view']);
        $this->assertRouteExists('GET', 'journals', ['auth:sanctum', 'company.access', 'permission:journal.view']);
        $this->assertRouteExists('GET', 'reports/profit-loss', ['auth:sanctum', 'company.access', 'permission:reports.view']);
        $this->assertRouteExists('GET', 'sales/invoices', ['auth:sanctum', 'company.access', 'permission:sales.invoices.view']);
        $this->assertRouteExists('GET', 'purchase/bills', ['auth:sanctum', 'company.access', 'permission:purchase.bills.view']);
        $this->assertRouteExists('GET', 'cash-bank/accounts', ['auth:sanctum', 'company.access', 'permission:cash_bank.view']);
        $this->assertRouteExists('GET', 'inventory/stock-balances', ['auth:sanctum', 'company.access', 'permission:inventory.stock.view']);
        $this->assertRouteExists('GET', 'access/users', ['auth:sanctum', 'company.access', 'permission:access.users.view']);
    }

    /**
     * @param array<int, string> $middleware
     */
    private function assertRouteExists(string $method, string $uri, array $middleware = []): void
    {
        $route = collect(Route::getRoutes())->first(function (LaravelRoute $route) use ($method, $uri) {
            return in_array(strtoupper($method), $route->methods(), true)
                && in_array(ltrim($route->uri(), '/'), [$uri, 'api/'.$uri], true);
        });

        $this->assertNotNull($route, "Expected {$method} {$uri} to be registered.");

        foreach ($middleware as $expectedMiddleware) {
            $this->assertContains(
                $expectedMiddleware,
                $route->gatherMiddleware(),
                "Expected {$method} {$uri} to include middleware {$expectedMiddleware}."
            );
        }
    }
}
