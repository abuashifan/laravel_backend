<?php

namespace Tests\Feature\Accounting;

use App\Models\FiscalYear;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\TenantAuditLog;
use App\Services\Accounting\FiscalYearService;
use App\Support\AccountMapping\AccountMappingKey;
use Tests\Feature\Journal\JournalTestCase;

class FiscalYearClosingTest extends JournalTestCase
{
    public function test_unauthenticated_close_rejected(): void
    {
        $res = $this->postJson('/api/accounting/fiscal-years/1/close', []);
        $res->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $fy = app(FiscalYearService::class)->getOrCreateActiveFiscalYear($ctx['company'], 2026);

        $res = $this->getJson('/api/accounting/fiscal-years/'.$fy->id.'/closing-preview');
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'X_COMPANY_ID_REQUIRED');
    }

    public function test_user_without_permission_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $fy = app(FiscalYearService::class)->getOrCreateActiveFiscalYear($ctx['company'], 2026);

        $res = $this->getJson('/api/accounting/fiscal-years/'.$fy->id.'/closing-preview', $ctx['headers']);
        $res->assertStatus(403);
        $res->assertJsonPath('code', 'PERMISSION_DENIED');
    }

    public function test_preview_and_close_open_fiscal_year_works_retained_earnings_calculated_audit_logged_and_fiscal_year_marked_closed(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $fy = app(FiscalYearService::class)->getOrCreateActiveFiscalYear($ctx['company'], 2026);

        $capital = ChartOfAccount::query()->create([
            'account_code' => '3000',
            'account_name' => 'Retained Earnings',
            'account_type' => 'equity',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        AccountMapping::query()->updateOrCreate(
            ['mapping_key' => AccountMappingKey::CLOSING_RETAINED_EARNINGS],
            ['module' => 'closing', 'account_id' => $capital->id, 'is_required' => true, 'is_active' => true]
        );

        $cashId = (int) $ctx['accounts']['debit'];
        $revenueId = (int) $ctx['accounts']['credit'];

        // Balanced posted journal inside FY: revenue 8m, equity 2m, cash 10m
        $j = JournalEntry::query()->create([
            'journal_number' => 'JV-CLOSE-1',
            'journal_date' => '2026-12-31',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $j->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 10000000, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $capital->id, 'debit' => 0, 'credit' => 2000000, 'line_order' => 2],
            ['account_id' => $revenueId, 'debit' => 0, 'credit' => 8000000, 'line_order' => 3],
        ]);

        // Closing requires preview first
        $closeWithoutPreview = $this->postJson('/api/accounting/fiscal-years/'.$fy->id.'/close', [], $ctx['headers']);
        $closeWithoutPreview->assertStatus(422);
        $closeWithoutPreview->assertJsonPath('code', 'VALIDATION_ERROR');

        $preview = $this->getJson('/api/accounting/fiscal-years/'.$fy->id.'/closing-preview', $ctx['headers'])
            ->assertStatus(200);
        // $preview->dump();
        $preview->assertJsonPath('data.valid', true);
        $this->assertSame(8000000.0, (float) $preview->json('data.preview.net_profit_loss'));

        $close = $this->postJson('/api/accounting/fiscal-years/'.$fy->id.'/close', ['closing_notes' => 'Year end close'], $ctx['headers'])
            ->assertStatus(200);
        $close->assertJsonPath('data.valid', true);
        $this->assertSame(8000000.0, (float) $close->json('data.retained_earnings_amount'));

        $fyFresh = FiscalYear::query()->findOrFail($fy->id);
        $this->assertSame('closed', $fyFresh->status);
        $this->assertTrue((bool) $fyFresh->is_closed);
        $this->assertNotNull($fyFresh->closed_at);

        $this->assertTrue(TenantAuditLog::query()->where('event', 'fiscal_year.closed')->exists());
    }
}
