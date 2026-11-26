<?php

namespace App\Modules\CashBank\Services;

use App\Modules\CashBank\Models\WorkspaceDisbursement;
use App\Modules\Journal\Models\JournalEntry;
use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\TenantConnectionManager;
use App\Shared\Tenant\TenantProductService;
use App\Shared\Workspace\ActivityAccountingGateway;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkspaceActivityAccountingGateway implements ActivityAccountingGateway
{
    public function __construct(
        private readonly TenantProductService $products,
        private readonly TenantConnectionManager $connections,
    ) {}

    public function receive(Company $company, array $approvedActivity): bool
    {
        return $this->withAccounting($company, function () use ($approvedActivity): bool {
            $approvedActivity['source_lines'] = json_encode($approvedActivity['source_lines'], JSON_THROW_ON_ERROR);
            WorkspaceDisbursement::query()->insertOrIgnore($approvedActivity + ['created_at' => now(), 'updated_at' => now()]);

            return true;
        }) ?? false;
    }

    public function status(Company $company, int $document): ?array
    {
        return $this->withAccounting($company, function () use ($document) {
            $row = WorkspaceDisbursement::query()->where('workspace_document_id', $document)->with('cashPayment')->first();
            if ($row === null) {
                return null;
            }
            $payment = $row->cashPayment;
            $journal = $payment?->journal_entry_id ? JournalEntry::query()->find($payment->journal_entry_id) : null;

            return [
                'id' => $row->id,
                'qc_status' => $row->qc_status,
                'qc_note' => $row->qc_note,
                'disbursed_on' => $row->disbursed_on?->toDateString(),
                'payment_reference' => $row->payment_reference,
                'payment_number' => $payment?->payment_number,
                'payment_status' => $payment?->status,
                'journal_entry_id' => $journal?->id,
                'journal_number' => $journal?->journal_number,
                'posted_on' => $payment?->status === 'posted' ? $journal?->journal_date?->toDateString() : null,
                'realized_minor' => $payment?->status === 'posted' && $journal?->status === 'posted' ? $row->amount_minor : 0,
            ];
        });
    }

    public function realization(Company $company, array $documents): ?array
    {
        return $this->withAccounting($company, function () use ($documents): array {
            $rows = $this->disbursements($documents);
            $journals = JournalEntry::query()->whereIn('id', $rows->pluck('cashPayment.journal_entry_id')->filter())
                ->get(['id', 'journal_number', 'journal_date', 'status'])->keyBy('id');

            return $rows->mapWithKeys(function (WorkspaceDisbursement $row) use ($journals): array {
                $payment = $row->cashPayment;
                $journal = $payment?->journal_entry_id ? $journals->get($payment->journal_entry_id) : null;
                $posted = $payment?->status === 'posted' && $journal?->status === 'posted';
                $lines = $posted ? $payment->lines
                    ->groupBy(fn ($line) => (int) ($line->metadata['workspace_line_id'] ?? 0))
                    ->map(fn (Collection $group) => $group->sum(fn ($line) => self::minor($line->amount)))
                    ->all() : [];
                $disbursed = $row->disbursed_on !== null;

                return [(int) $row->workspace_document_id => [
                    'disbursement_id' => $row->id,
                    'qc_status' => $row->qc_status,
                    'disbursed_on' => $row->disbursed_on?->toDateString(),
                    'disbursed_minor' => $disbursed ? (int) $row->amount_minor : 0,
                    'awaiting_minor' => $disbursed && ! $posted ? (int) $row->amount_minor : 0,
                    'realized_minor' => array_sum($lines),
                    'lines' => $lines,
                    'payment_number' => $payment?->payment_number,
                    'payment_status' => $payment?->status,
                    'journal_entry_id' => $journal?->id,
                    'journal_number' => $journal?->journal_number,
                    'posted_on' => $posted ? $journal->journal_date?->toDateString() : null,
                ]];
            })->all();
        });
    }

    public function reconciliation(Company $company, string $from, string $to, array $documents): ?array
    {
        return $this->withAccounting($company, function () use ($from, $to, $documents): array {
            $rows = $this->disbursements($documents);
            $linked = $rows->filter(fn (WorkspaceDisbursement $row) => $row->cashPayment?->status === 'posted')
                ->mapWithKeys(fn (WorkspaceDisbursement $row) => [(int) $row->cashPayment->journal_entry_id => $row]);

            // Biaya per jurnal posted dari akun beban: debit dikurangi kredit.
            $expense = fn () => DB::connection('tenant')->table('journal_entry_lines as l')
                ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
                ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
                ->where('j.status', 'posted')->where('a.account_type', 'expense');
            $inRange = $expense()->whereBetween('j.journal_date', [$from, $to])
                ->groupBy('j.id', 'j.journal_number', 'j.journal_date', 'j.description', 'j.source_type', 'j.source_number')
                ->orderBy('j.journal_date')->orderBy('j.id')
                ->get(['j.id', 'j.journal_number', 'j.journal_date', 'j.description', 'j.source_type', 'j.source_number',
                    DB::raw('SUM(l.debit) - SUM(l.credit) AS amount')]);
            $linkedJournals = $expense()->whereIn('j.id', $linked->keys())->groupBy('j.id')
                ->get(['j.id', DB::raw('SUM(l.debit) - SUM(l.credit) AS amount')])->keyBy('id');

            $unlinked = $inRange->reject(fn ($journal) => $linked->has((int) $journal->id));
            $mismatches = $linked->map(function (WorkspaceDisbursement $row, int $journalId) use ($linkedJournals): ?array {
                $journal = self::minor($linkedJournals->get($journalId)?->amount ?? 0);

                return $journal === (int) $row->amount_minor ? null : [
                    'workspace_document_id' => (int) $row->workspace_document_id, 'title' => $row->title,
                    'payment_number' => $row->cashPayment->payment_number,
                    'workspace_minor' => (int) $row->amount_minor, 'journal_minor' => $journal,
                ];
            })->filter()->values()->all();

            return [
                'gl_expense_minor' => $inRange->sum(fn ($journal) => self::minor($journal->amount)),
                'linked_in_range_minor' => $inRange->filter(fn ($journal) => $linked->has((int) $journal->id))
                    ->sum(fn ($journal) => self::minor($journal->amount)),
                'linked_total_minor' => $linkedJournals->sum(fn ($journal) => self::minor($journal->amount)),
                'unlinked_minor' => $unlinked->sum(fn ($journal) => self::minor($journal->amount)),
                'unlinked_count' => $unlinked->count(),
                'unlinked' => $unlinked->take(100)->map(fn ($journal) => [
                    'journal_entry_id' => (int) $journal->id, 'journal_number' => $journal->journal_number,
                    'journal_date' => substr((string) $journal->journal_date, 0, 10), 'description' => $journal->description,
                    'source_type' => $journal->source_type, 'source_number' => $journal->source_number,
                    'amount_minor' => self::minor($journal->amount),
                ])->values()->all(),
                'mismatches' => $mismatches,
            ];
        });
    }

    /** @param  list<int>  $documents */
    private function disbursements(array $documents): Collection
    {
        return $documents === [] ? collect() : WorkspaceDisbursement::query()
            ->whereIn('workspace_document_id', $documents)->with('cashPayment.lines')->get();
    }

    private static function minor(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function withAccounting(Company $company, Closure $callback): mixed
    {
        $accounting = $this->products->find($company, TenantDatabase::PRODUCT_ACCOUNTING);
        if ($accounting?->status !== 'active') {
            return null;
        }

        $this->connections->connect($accounting);
        try {
            return $callback();
        } finally {
            $this->connections->disconnect();
        }
    }
}
