<?php

namespace Tests\Feature\Accounting;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\JournalEntry;
use App\Services\Accounting\FiscalYearService;
use App\Support\AccountMapping\AccountMappingKey;
use Tests\Feature\Journal\JournalTestCase;

class ClosingWizardTest extends JournalTestCase
{
    public function test_checklist_endpoint_works_and_blocks_close_when_failed_and_allows_close_when_passed(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $fy = app(FiscalYearService::class)->getOrCreateActiveFiscalYear($ctx['company'], 2026);

        // No retained earnings mapping yet => checklist should fail can_close
        $check1 = $this->getJson('/api/accounting/fiscal-years/'.$fy->id.'/closing-checklist', $ctx['headers'])
            ->assertStatus(200);
        $this->assertFalse((bool) $check1->json('data.can_close'));

        // Configure retained earnings mapping and create balanced journal inside FY
        $retained = ChartOfAccount::query()->create([
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
            ['module' => 'closing', 'account_id' => $retained->id, 'is_required' => true, 'is_active' => true]
        );

        $cashId = (int) $ctx['accounts']['debit'];
        $revId = (int) $ctx['accounts']['credit'];

        $j = JournalEntry::query()->create([
            'journal_number' => 'JV-CW-1',
            'journal_date' => '2026-12-31',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $j->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 100, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $retained->id, 'debit' => 0, 'credit' => 20, 'line_order' => 2],
            ['account_id' => $revId, 'debit' => 0, 'credit' => 80, 'line_order' => 3],
        ]);

        $check2 = $this->getJson('/api/accounting/fiscal-years/'.$fy->id.'/closing-checklist', $ctx['headers'])
            ->assertStatus(200);
        $this->assertTrue((bool) $check2->json('data.can_close'));

        // Still cannot close without preview
        $close = $this->postJson('/api/accounting/fiscal-years/'.$fy->id.'/close', [], $ctx['headers'])
            ->assertStatus(422);
        $close->assertJsonPath('code', 'VALIDATION_ERROR');

        // Preview then close
        $this->getJson('/api/accounting/fiscal-years/'.$fy->id.'/closing-preview', $ctx['headers'])->assertStatus(200);
        $close2 = $this->postJson('/api/accounting/fiscal-years/'.$fy->id.'/close', [], $ctx['headers'])
            ->assertStatus(200);
        $close2->assertJsonPath('data.valid', true);
    }
}

