<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use Tests\Feature\Journal\JournalTestCase;

class DashboardTest extends JournalTestCase
{
    public function test_dashboard_endpoints_return_contract_for_owner(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $this->getJson('/api/dashboard/summary', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.total_receivable', 0)
            ->assertJsonPath('data.total_payable', 0)
            ->assertJsonPath('data.cash_balance', 0)
            ->assertJsonStructure(['data' => ['total_receivable', 'total_payable', 'cash_balance', 'current_month_profit']]);

        $this->getJson('/api/dashboard/pending', $ctx['headers'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['pending_invoices', 'pending_bills', 'low_stock_count', 'fiscal_year_days_remaining']]);

        $this->getJson('/api/dashboard/chart', $ctx['headers'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['sales_purchase', 'cash_flow']]);

        $this->getJson('/api/dashboard/activities', $ctx['headers'])
            ->assertOk()
            ->assertJsonStructure(['data' => []]);
    }
}