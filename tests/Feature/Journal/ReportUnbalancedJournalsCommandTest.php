<?php

namespace Tests\Feature\Journal;

use App\Modules\Journal\Models\JournalEntry;

class ReportUnbalancedJournalsCommandTest extends JournalTestCase
{
    public function test_lists_only_unbalanced_posted_journals(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $debit = (int) $ctx['accounts']['debit'];
        $credit = (int) $ctx['accounts']['credit'];

        $balanced = JournalEntry::query()->create(['journal_number' => 'JV-OK', 'journal_date' => '2026-01-05', 'status' => 'posted', 'is_obsolete' => false]);
        $balanced->lines()->createMany([
            ['account_id' => $debit, 'debit' => 500, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $credit, 'debit' => 0, 'credit' => 500, 'line_order' => 2],
        ]);

        $broken = JournalEntry::query()->create(['journal_number' => 'JV-RUSAK', 'journal_date' => '2026-01-06', 'status' => 'posted', 'is_obsolete' => false]);
        $broken->lines()->createMany([
            ['account_id' => $debit, 'debit' => 1000, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $credit, 'debit' => 0, 'credit' => 900, 'line_order' => 2],
        ]);

        $this->artisan('journal:report-unbalanced', ['--company' => $ctx['company']->id])
            ->expectsOutputToContain('journal=JV-RUSAK')
            ->doesntExpectOutputToContain('journal=JV-OK')
            ->assertSuccessful();
    }
}
