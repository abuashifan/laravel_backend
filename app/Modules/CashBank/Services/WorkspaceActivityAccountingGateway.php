<?php

namespace App\Modules\CashBank\Services;

use App\Modules\CashBank\Models\WorkspaceDisbursement;
use App\Modules\CashBank\Models\WorkspaceDisbursementTranche as Tranche;
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
        return $this->withAccounting($company, function () use ($document): ?array {
            $id = WorkspaceDisbursement::query()->where('workspace_document_id', $document)->value('id');

            return $id === null ? null : $this->describe($this->service()->load((int) $id));
        });
    }

    public function realization(Company $company, array $documents): ?array
    {
        return $this->withAccounting($company, fn (): array => $this->disbursements($documents)
            ->mapWithKeys(fn (WorkspaceDisbursement $row) => [(int) $row->workspace_document_id => $this->describe($row) + [
                'lines' => $this->service()->realization($row)['lines'],
            ]])->all());
    }

    public function reconciliation(Company $company, string $from, string $to, array $documents): ?array
    {
        return $this->withAccounting($company, function () use ($from, $to, $documents): array {
            // Jurnal yang dibuat oleh tahap kegiatan: kas keluar biaya langsung, kas keluar dana muka,
            // dan jurnal penyelesaian dana muka. Nilai harapan = beban yang seharusnya tercatat.
            $expected = [];
            foreach ($this->disbursements($documents) as $row) {
                foreach ($row->tranches->filter(fn (Tranche $tranche) => $tranche->isActive()) as $tranche) {
                    $payment = $tranche->cashPayment;
                    if ($payment?->status === 'posted' && $payment->journal_entry_id) {
                        $expected[(int) $payment->journal_entry_id] = [
                            'row' => $row, 'reference' => $payment->payment_number,
                            'expected' => $tranche->kind === Tranche::DIRECT ? $tranche->amount_minor : 0,
                        ];
                    }
                    if ($tranche->settlementJournal?->status === 'posted') {
                        $expected[(int) $tranche->settlement_journal_id] = [
                            'row' => $row, 'reference' => $tranche->settlementJournal->journal_number,
                            'expected' => collect($tranche->settlement_lines ?? [])->sum('amount_minor'),
                        ];
                    }
                }
            }
            $linked = collect($expected);

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
            $mismatches = $linked->map(function (array $link, int $journalId) use ($linkedJournals): ?array {
                $journal = self::minor($linkedJournals->get($journalId)?->amount ?? 0);

                return $journal === (int) $link['expected'] ? null : [
                    'workspace_document_id' => (int) $link['row']->workspace_document_id, 'title' => $link['row']->title,
                    'payment_number' => $link['reference'], 'workspace_minor' => (int) $link['expected'], 'journal_minor' => $journal,
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

    public function close(Company $company, int $document, int $actor): ?array
    {
        return $this->withAccounting($company, function () use ($document, $actor): array {
            $realization = $this->service()->close($document, $actor);

            return ['realized_minor' => $realization['realized_minor'], 'lines' => $realization['lines']];
        });
    }

    /** Ringkasan kegiatan dan tahapnya untuk Workspace; tidak memuat isi baris kas keluar. */
    private function describe(WorkspaceDisbursement $row): array
    {
        $service = $this->service();

        return $service->summary($row) + [
            'tranches' => $row->tranches->map(fn (Tranche $tranche) => collect($service->tranche($tranche))
                ->only(['id', 'sequence', 'kind', 'state', 'amount_minor', 'disbursed_on', 'payment_reference', 'qc_note', 'cash_payment', 'journal', 'settled_on', 'refund_minor', 'settlement_journal'])
                ->all())->all(),
        ];
    }

    /** @param  list<int>  $documents */
    private function disbursements(array $documents): Collection
    {
        if ($documents === []) {
            return collect();
        }
        $service = $this->service();

        return WorkspaceDisbursement::query()->whereIn('workspace_document_id', $documents)->pluck('id')
            ->map(fn ($id) => $service->load((int) $id));
    }

    private function service(): WorkspaceDisbursementService
    {
        return app(WorkspaceDisbursementService::class);
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
