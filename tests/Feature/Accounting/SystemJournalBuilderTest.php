<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Exceptions\ApiException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\StockMovement;
use App\Models\TenantDatabase;
use App\Services\Inventory\StockMovementJournalService;
use App\Services\Journal\SystemJournalBuilder;
use App\Services\Tenant\TenantContext;
use App\Support\AccountMapping\AccountMappingKey;
use Tests\Feature\Journal\JournalTestCase;

class SystemJournalBuilderTest extends JournalTestCase
{
    // -------------------------------------------------------------------------
    // H1 — Test 1: balanced lines create a valid system journal
    // -------------------------------------------------------------------------

    public function test_creates_journal_with_is_system_generated_true_and_correct_source(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $this->activateTenantContext($ctx['company']);

        $cashId = $ctx['accounts']['debit'];
        $revenueId = $ctx['accounts']['credit'];

        /** @var SystemJournalBuilder $builder */
        $builder = app(SystemJournalBuilder::class);

        $journal = $builder->create(
            [
                'source_type'   => 'stock_movement',
                'source_id'     => 99,
                'source_number' => 'SM-0001',
                'source_module' => 'inventory',
                'journal_date'  => '2026-05-01',
                'description'   => 'Opening stock journal SM-0001',
            ],
            [
                ['account_id' => $cashId,    'debit' => 500.0, 'credit' => 0.0,   'description' => 'Inventory',       'line_order' => 1],
                ['account_id' => $revenueId, 'debit' => 0.0,   'credit' => 500.0, 'description' => 'Opening Equity', 'line_order' => 2],
            ]
        );

        $this->assertInstanceOf(JournalEntry::class, $journal);
        $this->assertTrue((bool) $journal->is_system_generated);
        $this->assertSame('posted', (string) $journal->status);
        $this->assertSame('stock_movement', (string) $journal->source_type);
        $this->assertSame(99, (int) $journal->source_id);
        $this->assertSame('SM-0001', (string) $journal->source_number);
        $this->assertSame('inventory', (string) $journal->source_module);
        $this->assertSame('2026-05-01', substr((string) $journal->journal_date, 0, 10));

        $lines = $journal->lines;
        $this->assertCount(2, $lines);
        $this->assertSame(500.0, (float) $lines->sum('debit'));
        $this->assertSame(500.0, (float) $lines->sum('credit'));
    }

    // -------------------------------------------------------------------------
    // H1 — Test 2: unbalanced lines throw JOURNAL_NOT_BALANCED
    // -------------------------------------------------------------------------

    public function test_throws_journal_not_balanced_when_debits_do_not_equal_credits(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $this->activateTenantContext($ctx['company']);

        $cashId = $ctx['accounts']['debit'];
        $revenueId = $ctx['accounts']['credit'];

        /** @var SystemJournalBuilder $builder */
        $builder = app(SystemJournalBuilder::class);

        $this->expectException(ApiException::class);

        try {
            $builder->create(
                [
                    'source_type'  => 'stock_movement',
                    'source_id'    => 1,
                    'journal_date' => '2026-05-01',
                    'description'  => 'Unbalanced journal',
                ],
                [
                    ['account_id' => $cashId,    'debit' => 600.0, 'credit' => 0.0,   'line_order' => 1],
                    ['account_id' => $revenueId, 'debit' => 0.0,   'credit' => 500.0, 'line_order' => 2],
                ]
            );
        } catch (ApiException $e) {
            $this->assertSame('JOURNAL_NOT_BALANCED', $e->codeName);
            $this->assertSame(422, $e->status);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // H1 — Test 3: missing account_id throws JOURNAL_ACCOUNT_MISSING
    // -------------------------------------------------------------------------

    public function test_throws_journal_account_missing_when_line_has_no_account_id(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $this->activateTenantContext($ctx['company']);

        /** @var SystemJournalBuilder $builder */
        $builder = app(SystemJournalBuilder::class);

        $this->expectException(ApiException::class);

        try {
            $builder->create(
                [
                    'source_type'  => 'stock_movement',
                    'source_id'    => 1,
                    'journal_date' => '2026-05-01',
                    'description'  => 'Missing account journal',
                ],
                [
                    ['account_id' => null, 'debit' => 500.0, 'credit' => 0.0,   'line_order' => 1],
                    ['account_id' => null, 'debit' => 0.0,   'credit' => 500.0, 'line_order' => 2],
                ]
            );
        } catch (ApiException $e) {
            $this->assertSame('JOURNAL_ACCOUNT_MISSING', $e->codeName);
            $this->assertSame(422, $e->status);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // H1 — Test 4: StockMovementJournalService uses builder (is_system_generated)
    // -------------------------------------------------------------------------

    public function test_stock_movement_journal_service_creates_journal_via_builder(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $this->activateTenantContext($ctx['company']);

        $cashId = $ctx['accounts']['debit']; // asset account

        // OPENING_BALANCE_EQUITY requires account_type='equity' — create a dedicated one
        $equityAccount = ChartOfAccount::query()->create([
            'account_code' => '3100',
            'account_name' => 'Opening Balance Equity',
            'account_type' => 'equity',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        AccountMapping::query()->create([
            'mapping_key' => AccountMappingKey::INVENTORY_ASSET,
            'module'      => 'inventory',
            'account_id'  => $cashId,
            'is_required' => true,
            'is_active'   => true,
        ]);
        AccountMapping::query()->create([
            'mapping_key' => AccountMappingKey::OPENING_BALANCE_EQUITY,
            'module'      => 'inventory',
            'account_id'  => $equityAccount->id,
            'is_required' => true,
            'is_active'   => true,
        ]);

        $movement = StockMovement::query()->create([
            'movement_number' => 'SM-T001',
            'movement_type'   => 'opening_stock',
            'movement_date'   => '2026-05-01',
            'source_type'     => 'opening',
            'source_id'       => null,
            'total_value'     => 250.0,
            'status'          => 'posted',
        ]);

        /** @var StockMovementJournalService $service */
        $service = app(StockMovementJournalService::class);

        $journal = $service->createInventoryJournalForMovement($movement);

        $this->assertInstanceOf(JournalEntry::class, $journal);
        $this->assertTrue((bool) $journal->is_system_generated);
        $this->assertSame('posted', (string) $journal->status);
        $this->assertSame('stock_movement', (string) $journal->source_type);
        $this->assertSame((int) $movement->id, (int) $journal->source_id);
        $this->assertCount(2, $journal->lines);
        $this->assertSame(250.0, (float) $journal->lines->sum('debit'));
        $this->assertSame(250.0, (float) $journal->lines->sum('credit'));
    }

    // -------------------------------------------------------------------------
    // Helper — manually activate TenantContext for direct service calls
    // -------------------------------------------------------------------------

    private function activateTenantContext(Company $company): void
    {
        $tenantDb = TenantDatabase::query()->where('company_id', $company->id)->firstOrFail();
        $companyUser = CompanyUser::query()->where('company_id', $company->id)->firstOrFail();

        /** @var TenantContext $ctx */
        $ctx = app(TenantContext::class);
        $ctx->set($company, $companyUser, $tenantDb);
    }
}
