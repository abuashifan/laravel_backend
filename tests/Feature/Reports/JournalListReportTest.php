<?php

namespace Tests\Feature\Reports;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\Journal\Models\JournalEntryLine;
use App\Shared\Models\Company;
use App\Shared\Models\User;
use Tests\Feature\Journal\JournalTestCase;

class JournalListReportTest extends JournalTestCase
{
    protected array $connectionsToTransact = ['sqlite'];

    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/reports/journals')->assertStatus(401);
    }

    public function test_user_without_permission_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');

        $this->getJson('/api/reports/journals', $ctx['headers'])->assertStatus(403);
    }

    public function test_invalid_dates_return_422(): void
    {
        $ctx = $this->autoPostTenant();

        $this->getJson('/api/reports/journals?start_date=not-a-date', $ctx['headers'])
            ->assertStatus(422);
    }

    public function test_invalid_source_returns_422(): void
    {
        $ctx = $this->autoPostTenant();

        $this->getJson('/api/reports/journals?source=bogus', $ctx['headers'])
            ->assertStatus(422);
    }

    public function test_lists_posted_journals_with_totals(): void
    {
        $ctx = $this->autoPostTenant();

        $this->createManualJournal($ctx, '2026-06-01', 100);
        $this->createManualJournal($ctx, '2026-06-05', 250);

        $res = $this->getJson('/api/reports/journals?start_date=2026-06-01&end_date=2026-06-30', $ctx['headers'])
            ->assertStatus(200);

        $rows = $res->json('data.rows');
        $this->assertCount(2, $rows);
        $this->assertEquals(2, $res->json('data.totals.journal_count'));
        $this->assertEquals(350.0, $res->json('data.totals.total_debit'));
        $this->assertEquals(350.0, $res->json('data.totals.total_credit'));
        // Balanced per journal.
        $this->assertEquals($rows[0]['total_debit'], $rows[0]['total_credit']);
    }

    public function test_source_filter_narrows_by_module(): void
    {
        $ctx = $this->autoPostTenant();

        // Two manual journals (source_module = journal → Jurnal Umum).
        $this->createManualJournal($ctx, '2026-06-01', 100);
        $this->createManualJournal($ctx, '2026-06-02', 100);
        // One sales-sourced journal inserted directly.
        $this->createSourcedJournal($ctx, '2026-06-03', 500, 'sales', 'sales_invoice');

        $all = $this->getJson('/api/reports/journals?start_date=2026-06-01&end_date=2026-06-30', $ctx['headers'])
            ->assertStatus(200);
        $this->assertCount(3, $all->json('data.rows'));

        $general = $this->getJson('/api/reports/journals?start_date=2026-06-01&end_date=2026-06-30&source=general', $ctx['headers'])
            ->assertStatus(200);
        $this->assertCount(2, $general->json('data.rows'));

        $sales = $this->getJson('/api/reports/journals?start_date=2026-06-01&end_date=2026-06-30&source=sales', $ctx['headers'])
            ->assertStatus(200);
        $this->assertCount(1, $sales->json('data.rows'));
        $this->assertEquals('sales', $sales->json('data.rows.0.source_module'));
        $this->assertEquals(500.0, $sales->json('data.rows.0.total_debit'));

        $purchase = $this->getJson('/api/reports/journals?start_date=2026-06-01&end_date=2026-06-30&source=purchase', $ctx['headers'])
            ->assertStatus(200);
        $this->assertCount(0, $purchase->json('data.rows'));
    }

    public function test_inventory_source_filter(): void
    {
        $ctx = $this->autoPostTenant();

        $this->createManualJournal($ctx, '2026-06-01', 100);
        $this->createSourcedJournal($ctx, '2026-06-04', 750, 'inventory', 'stock_movement');

        $inventory = $this->getJson('/api/reports/journals?start_date=2026-06-01&end_date=2026-06-30&source=inventory', $ctx['headers'])
            ->assertStatus(200);
        $this->assertCount(1, $inventory->json('data.rows'));
        $this->assertEquals('inventory', $inventory->json('data.rows.0.source_module'));
        $this->assertEquals(750.0, $inventory->json('data.rows.0.total_debit'));
    }

    public function test_void_and_obsolete_journals_excluded(): void
    {
        $ctx = $this->autoPostTenant();

        $this->createManualJournal($ctx, '2026-06-01', 100);
        $this->createSourcedJournal($ctx, '2026-06-02', 300, 'sales', 'sales_invoice', status: 'void');

        $res = $this->getJson('/api/reports/journals?start_date=2026-06-01&end_date=2026-06-30', $ctx['headers'])
            ->assertStatus(200);

        $this->assertCount(1, $res->json('data.rows'));
    }

    public function test_empty_period_returns_empty_rows(): void
    {
        $ctx = $this->autoPostTenant();

        $res = $this->getJson('/api/reports/journals?start_date=2026-06-01&end_date=2026-06-30', $ctx['headers'])
            ->assertStatus(200);

        $this->assertSame([], $res->json('data.rows'));
        $this->assertEquals(0, $res->json('data.totals.journal_count'));
    }

    public function test_general_ledger_detail_mode_returns_lines_per_account(): void
    {
        $ctx = $this->autoPostTenant();

        $this->createManualJournal($ctx, '2026-06-01', 100);

        $res = $this->getJson('/api/reports/general-ledger?mode=detail&start_date=2026-06-01&end_date=2026-06-30', $ctx['headers'])
            ->assertStatus(200);

        $res->assertJsonPath('data.valid', true);
        $res->assertJsonPath('data.mode', 'detail');

        $accounts = $res->json('data.accounts');
        $this->assertNotEmpty($accounts);
        // Each returned account must carry its own lines array.
        foreach ($accounts as $account) {
            $this->assertArrayHasKey('lines', $account);
        }
        // At least one account has a posted line.
        $withLines = array_filter($accounts, fn ($a) => count($a['lines']) > 0);
        $this->assertNotEmpty($withLines);
    }

    /**
     * Tenant configured for auto-posting so manual journals land as posted (reportable).
     *
     * @return array{user: User, company: Company, headers: array<string,string>, tenant_path: string, accounts: array{debit:int,credit:int}}
     */
    private function autoPostTenant(): array
    {
        return $this->setUpTenant(role: 'finance', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'simple_auto_post',
            'auto_post_transactions' => true,
        ]);
    }

    /**
     * @param  array{headers: array<string,string>, accounts: array{debit:int,credit:int}}  $ctx
     */
    private function createManualJournal(array $ctx, string $date, float $amount): int
    {
        $res = $this->postJson('/api/journals', [
            'journal_date' => $date,
            'description' => 'Manual journal '.$date,
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => $amount],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => $amount],
            ],
        ], $ctx['headers'])->assertStatus(201);

        return (int) $res->json('data.id');
    }

    /**
     * Insert a posted journal with an arbitrary source_module directly (system-generated style),
     * used to exercise the source filter without spinning up the full sales/purchase pipeline.
     *
     * @param  array{accounts: array{debit:int,credit:int}}  $ctx
     */
    private function createSourcedJournal(array $ctx, string $date, float $amount, string $module, string $type, string $status = 'posted'): int
    {
        $journal = JournalEntry::query()->create([
            'journal_number' => strtoupper($module).'-'.uniqid(),
            'journal_date' => $date,
            'description' => ucfirst($module).' source '.$date,
            'status' => $status,
            'revision_no' => 1,
            'source_type' => $type,
            'source_module' => $module,
            'is_system_generated' => true,
            'is_obsolete' => false,
            'created_by' => $ctx['user']->id,
            'posted_by' => $status === 'posted' ? $ctx['user']->id : null,
            'posted_at' => $status === 'posted' ? now() : null,
        ]);

        JournalEntryLine::query()->create([
            'journal_entry_id' => $journal->id,
            'account_id' => $ctx['accounts']['debit'],
            'description' => 'Debit line',
            'debit' => $amount,
            'credit' => 0,
            'line_order' => 1,
        ]);
        JournalEntryLine::query()->create([
            'journal_entry_id' => $journal->id,
            'account_id' => $ctx['accounts']['credit'],
            'description' => 'Credit line',
            'debit' => 0,
            'credit' => $amount,
            'line_order' => 2,
        ]);

        return (int) $journal->id;
    }
}
