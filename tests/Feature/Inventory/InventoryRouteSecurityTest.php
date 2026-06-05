<?php

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\Route;
use Tests\Feature\Journal\JournalTestCase;

class InventoryRouteSecurityTest extends JournalTestCase
{
    public function test_inventory_routes_are_protected_by_auth_company_and_permissions(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->uri(), 'api/inventory'))
            ->values();

        $this->assertGreaterThan(0, $routes->count());

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, (string) $route->uri());
            $this->assertContains('company.access', $middleware, (string) $route->uri());
            $this->assertTrue(
                collect($middleware)->contains(fn (string $item): bool => str_starts_with($item, 'permission:')),
                (string) $route->uri()
            );
        }
    }

    public function test_unauthenticated_user_cannot_access_inventory_reports(): void
    {
        $this->getJson('/api/inventory/reports/stock-balances')->assertStatus(401);
    }

    public function test_missing_company_header_is_rejected(): void
    {
        $this->setUpTenant(role: 'warehouse');
        $this->getJson('/api/inventory/reports/stock-balances')->assertStatus(422);
    }

    public function test_user_without_permission_is_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'viewer');
        $this->getJson('/api/inventory/reports/stock-balances', $ctx['headers'])->assertStatus(403);
    }
}

