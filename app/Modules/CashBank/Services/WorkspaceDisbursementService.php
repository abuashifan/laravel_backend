<?php

namespace App\Modules\CashBank\Services;

use App\Modules\CashBank\Models\CashPayment;
use App\Modules\CashBank\Models\CashPaymentLine;
use App\Modules\CashBank\Models\WorkspaceDisbursement;
use App\Modules\CashBank\Models\WorkspaceDisbursementTranche as Tranche;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\Journal\Services\SystemJournalBuilder;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Shared\Exceptions\ApiException;
use App\Shared\TransactionLifecycle\TransactionDateGuardService;
use App\Shared\TransactionLifecycle\TransactionVoidEffectService;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pencairan RAB Kegiatan Workspace di Accounting (Fase 3 dan 5).
 *
 * Satu kegiatan dicairkan dalam satu atau beberapa tahap. Tahap biaya langsung
 * menjadi kas keluar ke akun biaya. Tahap dana muka menjadi kas keluar ke akun
 * uang muka, lalu diselesaikan dengan jurnal biaya terverifikasi dan pengembalian.
 */
class WorkspaceDisbursementService
{
    public const STATES = ['not_disbursed', 'awaiting_qc', 'returned', 'awaiting_posting', 'payment_void', 'awaiting_settlement', 'complete', 'closed'];

    public function __construct(
        private readonly CashPaymentService $cashPayments,
        private readonly CashBankAccountService $cashAccounts,
        private readonly SystemJournalBuilder $journals,
        private readonly TransactionDateGuardService $dateGuard,
        private readonly TransactionVoidEffectService $voidEffects,
    ) {}

    public function index(array $filters): array
    {
        $rows = WorkspaceDisbursement::query()->with('tranches.cashPayment:id,payment_number,status,journal_entry_id', 'tranches.settlementJournal:id,status')
            ->orderByDesc('id')->get()
            ->map(fn (WorkspaceDisbursement $row) => $this->summary($row))
            ->when(isset($filters['state']), fn (Collection $rows) => $rows->where('state', $filters['state']))
            ->values();
        $perPage = (int) ($filters['per_page'] ?? 25);

        return ['data' => $rows->forPage((int) ($filters['page'] ?? 1), $perPage)->values()->all(), 'total' => $rows->count()];
    }

    public function show(int $id): array
    {
        $row = $this->load($id);
        $history = DB::connection('tenant')->table('workspace_disbursement_events')
            ->where('workspace_disbursement_id', $id)->orderBy('id')->get()
            ->map(fn ($event) => [
                'action' => $event->action, 'created_at' => $event->created_at, 'user_id' => $event->user_id, 'tranche_id' => $event->tranche_id,
                'details' => json_decode($event->details, true, 512, JSON_THROW_ON_ERROR),
            ]);
        $allocated = $this->allocated($row);

        return $this->summary($row) + [
            'source_version' => $row->source_version,
            'workspace_period_id' => $row->workspace_period_id,
            'source_lines' => collect($row->source_lines)->map(fn (array $line) => $line + [
                'allocated_minor' => $allocated[$line['id']] ?? 0,
                'remaining_minor' => (int) $line['amount_minor'] - ($allocated[$line['id']] ?? 0),
            ])->all(),
            'tranches' => $row->tranches->map(fn (Tranche $tranche) => $this->tranche($tranche))->all(),
            'history' => $history->all(),
            'suggested_lines' => $this->suggestedLines($row),
            'suggested_advance_account' => $this->suggestedAdvanceAccount(),
        ];
    }

    public function recordTranche(int $id, array $data, int $actor): array
    {
        $this->locked($id, function (WorkspaceDisbursement $row) use ($data, $actor): void {
            $this->assertOpen($row);
            $lines = $this->trancheLines($row, $data['lines'], null);
            $tranche = $row->tranches()->create($this->trancheFields($data, $lines) + [
                'sequence' => (int) $row->tranches()->max('sequence') + 1,
                'recorded_by' => $actor, 'recorded_at' => now(),
            ]);
            $this->event($row, $tranche, 'record_tranche', $actor, ['tranche' => $tranche->sequence, 'kind' => $tranche->kind, 'amount_minor' => $tranche->amount_minor]);
        });

        return $this->show($id);
    }

    public function updateTranche(int $id, int $trancheId, array $data, int $actor): array
    {
        $this->locked($id, function (WorkspaceDisbursement $row) use ($trancheId, $data, $actor): void {
            $this->assertOpen($row);
            $tranche = $this->editableTranche($row, $trancheId);
            $lines = $this->trancheLines($row, $data['lines'], $tranche->id);
            $before = $tranche->only(['kind', 'amount_minor', 'disbursed_on', 'cash_bank_account_id', 'payment_reference']);
            $returned = $tranche->qc_status === 'rejected';
            $tranche->update($this->trancheFields($data, $lines) + ['recorded_by' => $actor, 'recorded_at' => now()]
                + ($returned ? ['qc_status' => 'pending', 'qc_at' => null, 'qc_by' => null] : []));
            $this->event($row, $tranche, 'record_disbursement', $actor, ['tranche' => $tranche->sequence, 'previous' => $before, 'resubmitted' => $returned]);
        });

        return $this->show($id);
    }

    public function cancelTranche(int $id, int $trancheId, string $note, int $actor): array
    {
        $this->locked($id, function (WorkspaceDisbursement $row) use ($trancheId, $note, $actor): void {
            $tranche = $this->editableTranche($row, $trancheId);
            $tranche->update(['status' => 'cancelled']);
            $this->event($row, $tranche, 'cancel_tranche', $actor, ['tranche' => $tranche->sequence, 'note' => $note]);
        });

        return $this->show($id);
    }

    public function review(int $id, int $trancheId, string $decision, array $data, int $actor): array
    {
        $this->locked($id, function (WorkspaceDisbursement $row) use ($trancheId, $decision, $data, $actor): void {
            $tranche = $this->activeTranche($row, $trancheId);
            if ($tranche->qc_status === 'approved') {
                if ($decision === 'approve') {
                    return;
                }
                throw ApiException::make('WORKSPACE_QC_ALREADY_APPROVED', 'Buka kembali QC sebelum menolak tahap yang sudah disetujui.', 409);
            }

            $note = $data['note'] ?? null;
            if ($decision === 'reject') {
                $tranche->update(['qc_status' => 'rejected', 'qc_note' => $note, 'qc_at' => now(), 'qc_by' => $actor]);
                $this->event($row, $tranche, 'reject_qc', $actor, ['tranche' => $tranche->sequence, 'note' => $note]);

                return;
            }

            $advance = null;
            if ($tranche->kind === Tranche::ADVANCE) {
                $advance = $this->advanceAccount((int) ($data['advance_account_id'] ?? 0));
                $lines = [[
                    'account_id' => $advance->id, 'amount' => $this->decimal($tranche->amount_minor),
                    'description' => 'Dana muka '.$row->title,
                    'metadata' => ['workspace_tranche_id' => $tranche->id, 'workspace_advance' => true],
                ]];
            } else {
                $lines = $this->expenseLines($row, $tranche, $tranche->lines, $data['lines'] ?? []);
            }
            $payment = $this->cashPayments->create([
                'payment_date' => $tranche->disbursed_on->toDateString(),
                'cash_bank_account_id' => $tranche->cash_bank_account_id,
                'currency_code' => 'IDR',
                'exchange_rate' => 1,
                'amount' => $this->decimal($tranche->amount_minor),
                'notes' => $row->title.' · RAB Workspace #'.$row->workspace_document_id.' · tahap '.$tranche->sequence
                    .($advance ? ' (dana muka)' : '').' · '.$tranche->payment_reference,
                'metadata' => ['workspace_disbursement_id' => $row->id, 'workspace_document_id' => $row->workspace_document_id, 'workspace_tranche_id' => $tranche->id],
                'lines' => $lines,
            ]);
            $tranche->update([
                'qc_status' => 'approved', 'qc_note' => $note, 'qc_at' => now(), 'qc_by' => $actor,
                'cash_payment_id' => $payment->id, 'advance_account_id' => $advance?->id,
            ]);
            $this->event($row, $tranche, 'approve_qc', $actor, [
                'tranche' => $tranche->sequence, 'note' => $note, 'cash_payment_id' => $payment->id,
                'accounts' => $data['lines'] ?? [], 'advance_account_id' => $advance?->id,
            ]);
        });

        return $this->show($id);
    }

    public function reopen(int $id, int $trancheId, string $note, int $actor): array
    {
        $this->locked($id, function (WorkspaceDisbursement $row) use ($trancheId, $note, $actor): void {
            $this->assertOpen($row);
            $tranche = $this->activeTranche($row, $trancheId);
            $payment = CashPayment::query()->lockForUpdate()->find($tranche->cash_payment_id);
            if (! in_array($payment?->status, ['draft', 'void'], true)) {
                throw ApiException::make('WORKSPACE_QC_CANNOT_REOPEN', 'QC dapat dibuka kembali saat kas keluar masih draf atau sudah dibatalkan (void).', 409);
            }

            $tranche->update(['qc_status' => 'pending', 'qc_note' => null, 'qc_at' => null, 'qc_by' => null, 'cash_payment_id' => null, 'advance_account_id' => null]);
            // Draf dihapus. Kas keluar void tetap tersimpan untuk audit dan hanya dilepas dari tahap.
            if ($payment->status === 'draft') {
                $this->cashPayments->delete($payment, $note);
            }
            $this->event($row, $tranche, 'reopen_qc', $actor, [
                'tranche' => $tranche->sequence, 'note' => $note,
                'previous_cash_payment_id' => $payment->id, 'previous_cash_payment_status' => $payment->status,
            ]);
        });

        return $this->show($id);
    }

    public function settle(int $id, int $trancheId, array $data, int $actor): array
    {
        $this->locked($id, function (WorkspaceDisbursement $row) use ($trancheId, $data, $actor): void {
            $tranche = $this->activeTranche($row, $trancheId);
            if ($tranche->state() !== 'awaiting_settlement') {
                throw ApiException::make('WORKSPACE_ADVANCE_NOT_SETTLEABLE', 'Dana muka dapat diselesaikan setelah kas keluarnya diposting dan belum pernah diselesaikan.', 409);
            }
            $settledOn = (string) $data['settled_on'];
            if ($settledOn < $tranche->disbursed_on->toDateString()) {
                throw ApiException::make('WORKSPACE_SETTLEMENT_DATE_INVALID', 'Tanggal penyelesaian tidak boleh sebelum tanggal pencairan.', 422);
            }
            $this->guardDate($settledOn, 'post');

            $limits = collect($tranche->lines)->pluck('amount_minor', 'source_line_id')->map(fn ($amount) => (int) $amount);
            $verified = collect($data['lines'])->keyBy('source_line_id')->map(fn (array $line) => $line + ['amount_minor' => $this->minor($line['amount'])]);
            foreach ($verified as $lineId => $line) {
                if (! $limits->has($lineId) || $line['amount_minor'] > $limits[$lineId]) {
                    throw ApiException::make('WORKSPACE_SETTLEMENT_EXCEEDS_ADVANCE', 'Biaya terverifikasi per baris tidak boleh melebihi dana muka baris itu.', 422);
                }
            }
            $spent = $verified->sum('amount_minor');
            $refund = $tranche->amount_minor - $spent;
            $refundAccount = null;
            if ($refund > 0) {
                $refundAccount = $this->cashBankAccount((int) ($data['refund_cash_bank_account_id'] ?? 0));
            }

            $costs = $verified->filter(fn (array $line) => $line['amount_minor'] > 0);
            $expense = $costs->isEmpty() ? [] : collect($this->expenseLines($row, $tranche,
                $costs->map(fn (array $line) => ['source_line_id' => $line['source_line_id'], 'amount_minor' => $line['amount_minor']])->values()->all(),
                $costs->map(fn (array $line) => array_intersect_key($line, array_flip(['source_line_id', 'account_id', 'department_id', 'project_id'])))->values()->all(),
            ))->map(fn (array $line) => $line + ['debit' => (float) $line['amount'], 'credit' => 0])->all();
            $journalLines = array_merge(
                array_map(fn (array $line) => array_diff_key($line, ['amount' => true]), $expense),
                $refund > 0 ? [['account_id' => $refundAccount->id, 'debit' => (float) $this->decimal($refund), 'credit' => 0, 'description' => 'Pengembalian sisa dana muka']] : [],
                [['account_id' => $tranche->advance_account_id, 'debit' => 0, 'credit' => (float) $this->decimal($tranche->amount_minor), 'description' => 'Penyelesaian dana muka']],
            );
            foreach ($journalLines as $index => &$line) {
                $line['line_order'] = $index + 1;
                $line['metadata'] = ($line['metadata'] ?? []) + ['workspace_tranche_id' => $tranche->id];
            }
            unset($line);

            $journal = $this->journals->create([
                'source_type' => 'workspace_advance_settlement', 'source_id' => $tranche->id,
                'source_number' => $tranche->cashPayment?->payment_number, 'source_module' => 'cash_bank',
                'journal_date' => $settledOn,
                'description' => 'Penyelesaian dana muka '.$row->title.' · tahap '.$tranche->sequence,
            ], $journalLines);

            $tranche->update([
                'settled_on' => $settledOn, 'settlement_journal_id' => $journal->id,
                'settlement_lines' => $verified->map(fn (array $line) => [
                    'source_line_id' => (int) $line['source_line_id'], 'amount_minor' => $line['amount_minor'],
                    'account_id' => $line['account_id'] ?? null, 'department_id' => $line['department_id'] ?? null, 'project_id' => $line['project_id'] ?? null,
                ])->values()->all(),
                'refund_minor' => $refund, 'refund_cash_bank_account_id' => $refundAccount?->id,
                'settled_by' => $actor, 'settled_at' => now(),
            ]);
            $this->event($row, $tranche, 'settle_advance', $actor, [
                'tranche' => $tranche->sequence, 'journal_entry_id' => $journal->id, 'spent_minor' => $spent, 'refund_minor' => $refund, 'note' => $data['note'] ?? null,
            ]);
        });

        return $this->show($id);
    }

    public function cancelSettlement(int $id, int $trancheId, string $note, int $actor): array
    {
        $this->locked($id, function (WorkspaceDisbursement $row) use ($trancheId, $note, $actor): void {
            $this->assertOpen($row);
            $tranche = $this->activeTranche($row, $trancheId);
            if ($tranche->settlement_journal_id === null) {
                throw ApiException::make('WORKSPACE_SETTLEMENT_MISSING', 'Dana muka ini belum diselesaikan.', 409);
            }
            $this->guardDate($tranche->settled_on->toDateString(), 'void');
            $journal = $tranche->settlement_journal_id;
            $this->voidEffects->voidJournalById($journal, $note);
            $tranche->update([
                'settled_on' => null, 'settlement_journal_id' => null, 'settlement_lines' => null,
                'refund_minor' => 0, 'refund_cash_bank_account_id' => null, 'settled_by' => null, 'settled_at' => null,
            ]);
            $this->event($row, $tranche, 'cancel_settlement', $actor, ['tranche' => $tranche->sequence, 'note' => $note, 'voided_journal_id' => $journal]);
        });

        return $this->show($id);
    }

    /**
     * Tandai kegiatan selesai bila semua tahap tuntas, lalu kembalikan realisasi per baris.
     * Aman diulang: kegiatan yang sudah selesai mengembalikan angka yang sama.
     */
    public function close(int $documentId, int $actor): array
    {
        $row = WorkspaceDisbursement::query()->where('workspace_document_id', $documentId)->first();
        if ($row === null) {
            throw ApiException::make('WORKSPACE_DISBURSEMENT_MISSING', 'Kegiatan belum diterima Accounting.', 409);
        }
        $this->locked($row->id, function (WorkspaceDisbursement $row) use ($actor): void {
            if ($row->closed_at !== null) {
                return;
            }
            $open = $row->tranches->filter(fn (Tranche $tranche) => ! in_array($tranche->state(), ['complete', 'cancelled'], true));
            if ($open->isNotEmpty()) {
                throw ApiException::make('WORKSPACE_ACTIVITY_NOT_SETTLED', 'Tuntaskan semua tahap pencairan (QC, posting, dan penyelesaian dana muka) sebelum menyelesaikan kegiatan.', 409, [
                    'tranches' => $open->map(fn (Tranche $tranche) => ['sequence' => $tranche->sequence, 'state' => $tranche->state()])->values()->all(),
                ]);
            }
            $row->update(['closed_at' => now(), 'closed_by' => $actor]);
            $this->event($row, null, 'close_activity', $actor, ['realized_minor' => array_sum($this->realization($row)['lines'])]);
        });

        return $this->realization($this->load($row->id));
    }

    /**
     * Angka yang dibaca Workspace: dicairkan, menunggu, realisasi per baris, dan pengembalian.
     *
     * @return array{disbursed_minor:int, awaiting_minor:int, realized_minor:int, refund_minor:int, lines:array<int,int>}
     */
    public function realization(WorkspaceDisbursement $row): array
    {
        $lines = [];
        $refund = 0;
        $disbursed = 0;
        foreach ($row->tranches as $tranche) {
            if (! $tranche->isActive()) {
                continue;
            }
            $disbursed += $tranche->amount_minor;
            $payment = $tranche->cashPayment;
            if ($tranche->kind === Tranche::DIRECT && $payment?->status === 'posted' && $this->journalPosted($payment->journal_entry_id)) {
                foreach ($payment->lines as $line) {
                    $source = (int) ($line->metadata['workspace_line_id'] ?? 0);
                    $lines[$source] = ($lines[$source] ?? 0) + $this->minor($line->amount);
                }
            }
            if ($tranche->kind === Tranche::ADVANCE && $tranche->settlementJournal?->status === 'posted') {
                foreach ($tranche->settlement_lines ?? [] as $line) {
                    $lines[(int) $line['source_line_id']] = ($lines[(int) $line['source_line_id']] ?? 0) + (int) $line['amount_minor'];
                }
                $refund += $tranche->refund_minor;
            }
        }
        $realized = array_sum($lines);

        return [
            'disbursed_minor' => $disbursed, 'realized_minor' => $realized, 'refund_minor' => $refund,
            'awaiting_minor' => max(0, $disbursed - $realized - $refund), 'lines' => $lines,
        ];
    }

    public function load(int $id): WorkspaceDisbursement
    {
        return WorkspaceDisbursement::query()->with(
            'tranches.cashPayment:id,payment_number,status,journal_entry_id', 'tranches.cashPayment.lines',
            'tranches.cashBankAccount:id,account_code,account_name', 'tranches.advanceAccount:id,account_code,account_name',
            'tranches.settlementJournal:id,journal_number,journal_date,status',
        )->findOrFail($id);
    }

    public function summary(WorkspaceDisbursement $row): array
    {
        $realization = $this->realization($row);
        $states = $row->tranches->map(fn (Tranche $tranche) => $tranche->state())->reject(fn ($state) => $state === 'cancelled');
        $state = match (true) {
            $row->closed_at !== null => 'closed',
            $states->isEmpty() => 'not_disbursed',
            default => collect(['returned', 'awaiting_qc', 'payment_void', 'awaiting_posting', 'awaiting_settlement'])
                ->first(fn ($candidate) => $states->contains($candidate)) ?? 'complete',
        };

        return [
            'id' => $row->id, 'workspace_document_id' => (int) $row->workspace_document_id, 'unit_name' => $row->unit_name,
            'title' => $row->title, 'activity_date' => $row->activity_date?->toDateString(), 'amount_minor' => (int) $row->amount_minor,
            'state' => $state, 'closed_at' => $row->closed_at?->toIso8601String(), 'tranche_count' => $states->count(),
        ] + collect($realization)->except('lines')->all();
    }

    public function tranche(Tranche $tranche): array
    {
        $payment = $tranche->cashPayment;
        $journal = $payment?->journal_entry_id ? JournalEntry::query()->find($payment->journal_entry_id, ['id', 'journal_number', 'journal_date', 'status']) : null;

        return [
            'id' => $tranche->id, 'sequence' => $tranche->sequence, 'kind' => $tranche->kind, 'status' => $tranche->status,
            'state' => $tranche->state(), 'amount_minor' => $tranche->amount_minor, 'lines' => $tranche->lines,
            'disbursed_on' => $tranche->disbursed_on?->toDateString(), 'payment_reference' => $tranche->payment_reference,
            'cash_bank_account' => $tranche->cashBankAccount?->only(['id', 'account_code', 'account_name']),
            'qc_status' => $tranche->qc_status, 'qc_note' => $tranche->qc_note,
            'advance_account' => $tranche->advanceAccount?->only(['id', 'account_code', 'account_name']),
            'cash_payment' => $payment ? ['id' => $payment->id, 'payment_number' => $payment->payment_number, 'status' => $payment->status] : null,
            'journal' => $journal ? ['id' => $journal->id, 'journal_number' => $journal->journal_number, 'journal_date' => $journal->journal_date?->toDateString(), 'status' => $journal->status] : null,
            'settled_on' => $tranche->settled_on?->toDateString(), 'settlement_lines' => $tranche->settlement_lines,
            'refund_minor' => $tranche->refund_minor,
            'settlement_journal' => $tranche->settlementJournal?->only(['id', 'journal_number', 'status']),
        ];
    }

    private function trancheFields(array $data, array $lines): array
    {
        $cash = $this->cashBankAccount((int) $data['cash_bank_account_id']);

        return [
            'kind' => $data['kind'], 'lines' => $lines, 'amount_minor' => array_sum(array_column($lines, 'amount_minor')),
            'disbursed_on' => $data['disbursed_on'], 'cash_bank_account_id' => $cash->id, 'payment_reference' => $data['payment_reference'],
        ];
    }

    /**
     * Nominal tahap per baris RAB. Jumlah tahap aktif per baris tidak boleh melebihi RAB baris itu.
     *
     * @return list<array{source_line_id:int, amount_minor:int}>
     */
    private function trancheLines(WorkspaceDisbursement $row, array $input, ?int $exclude): array
    {
        $approved = collect($row->source_lines)->mapWithKeys(fn (array $line) => [(int) $line['id'] => (int) $line['amount_minor']]);
        $allocated = $this->allocated($row, $exclude);
        $lines = collect($input)->map(fn (array $line) => ['source_line_id' => (int) $line['source_line_id'], 'amount_minor' => $this->minor($line['amount'])])
            ->filter(fn (array $line) => $line['amount_minor'] > 0)->values();
        if ($lines->isEmpty()) {
            throw ApiException::make('WORKSPACE_TRANCHE_EMPTY', 'Isi nominal pencairan pada sedikitnya satu baris.', 422);
        }
        foreach ($lines as $line) {
            if (! $approved->has($line['source_line_id'])) {
                throw ApiException::make('WORKSPACE_DISBURSEMENT_LINE_MISMATCH', 'Baris pencairan harus berasal dari RAB yang disetujui.', 422);
            }
            if (($allocated[$line['source_line_id']] ?? 0) + $line['amount_minor'] > $approved[$line['source_line_id']]) {
                throw ApiException::make('WORKSPACE_TRANCHE_EXCEEDS_BUDGET', 'Jumlah pencairan per baris tidak boleh melebihi RAB yang disetujui.', 422, [
                    'source_line_id' => $line['source_line_id'], 'remaining_minor' => $approved[$line['source_line_id']] - ($allocated[$line['source_line_id']] ?? 0),
                ]);
            }
        }

        return $lines->all();
    }

    /** @return array<int, int> nominal tahap aktif per baris RAB. */
    private function allocated(WorkspaceDisbursement $row, ?int $exclude = null): array
    {
        $allocated = [];
        foreach ($row->tranches as $tranche) {
            if ($tranche->isActive() && $tranche->id !== $exclude) {
                foreach ($tranche->lines as $line) {
                    $allocated[(int) $line['source_line_id']] = ($allocated[(int) $line['source_line_id']] ?? 0) + (int) $line['amount_minor'];
                }
            }
        }

        return $allocated;
    }

    /** @param  list<array{source_line_id:int, amount_minor:int}>  $amounts */
    private function expenseLines(WorkspaceDisbursement $row, Tranche $tranche, array $amounts, array $decisions): array
    {
        $mapping = collect($decisions)->keyBy('source_line_id');
        $needed = collect($amounts)->pluck('source_line_id')->map(fn ($id) => (int) $id);
        if ($mapping->count() !== $needed->count() || $needed->diff($mapping->keys()->map(fn ($id) => (int) $id))->isNotEmpty()) {
            throw ApiException::make('WORKSPACE_DISBURSEMENT_LINE_MISMATCH', 'Pilih akun untuk setiap baris tahap ini.', 422);
        }

        $accountIds = $mapping->pluck('account_id')->unique();
        $expenses = ChartOfAccount::query()->whereIn('id', $accountIds)->where('is_active', true)->where('account_type', 'expense')->count();
        if ($expenses !== $accountIds->count()) {
            throw ApiException::make('WORKSPACE_EXPENSE_ACCOUNT_REQUIRED', 'Setiap baris harus memakai akun biaya aktif.', 422);
        }

        $sources = collect($row->source_lines)->keyBy('id');

        return array_map(function (array $line) use ($mapping, $sources, $tranche) {
            $source = $sources[$line['source_line_id']];
            $decision = $mapping[$line['source_line_id']];

            return [
                'account_id' => $decision['account_id'],
                'department_id' => $decision['department_id'] ?? null,
                'project_id' => $decision['project_id'] ?? null,
                'amount' => $this->decimal((int) $line['amount_minor']),
                'description' => $source['description'],
                'metadata' => ['workspace_line_id' => (int) $source['id'], 'workspace_budget_code' => $source['budget_code'], 'workspace_tranche_id' => $tranche->id],
            ];
        }, $amounts);
    }

    private function editableTranche(WorkspaceDisbursement $row, int $trancheId): Tranche
    {
        $tranche = $this->activeTranche($row, $trancheId);
        if ($tranche->qc_status === 'approved') {
            throw ApiException::make('WORKSPACE_QC_ALREADY_APPROVED', 'Tahap ini sudah melewati QC. Buka kembali QC sebelum mengoreksi atau membatalkan.', 409);
        }

        return $tranche;
    }

    private function activeTranche(WorkspaceDisbursement $row, int $trancheId): Tranche
    {
        $tranche = $row->tranches->firstWhere('id', $trancheId);
        abort_if($tranche === null, 404);
        if (! $tranche->isActive()) {
            throw ApiException::make('WORKSPACE_TRANCHE_CANCELLED', 'Tahap ini sudah dibatalkan.', 409);
        }

        return $tranche;
    }

    private function assertOpen(WorkspaceDisbursement $row): void
    {
        if ($row->closed_at !== null) {
            throw ApiException::make('WORKSPACE_ACTIVITY_CLOSED', 'Kegiatan sudah diselesaikan di Workspace.', 409);
        }
    }

    private function cashBankAccount(int $id): ChartOfAccount
    {
        $account = ChartOfAccount::query()->whereKey($id)->where('is_active', true)->first();
        if (! $account || ! $this->cashAccounts->isCashBankAccount($id)) {
            throw ApiException::make('CASH_BANK_ACCOUNT_REQUIRED', 'Pilih akun kas atau bank yang aktif.', 422);
        }

        return $account;
    }

    private function advanceAccount(int $id): ChartOfAccount
    {
        $account = ChartOfAccount::query()->whereKey($id)->where('is_active', true)->where('account_type', 'asset')
            ->where('is_cash_bank', false)->whereDoesntHave('children')->first();
        if (! $account) {
            throw ApiException::make('WORKSPACE_ADVANCE_ACCOUNT_REQUIRED', 'Pilih akun uang muka (aset, bukan kas/bank) yang aktif.', 422);
        }

        return $account;
    }

    private function journalPosted(?int $journalId): bool
    {
        return $journalId !== null && JournalEntry::query()->whereKey($journalId)->where('status', 'posted')->exists();
    }

    /**
     * Usulan akun dan dimensi per baris: pilihan QC terakhir untuk kode pos yang sama
     * dari kas keluar Workspace yang tidak dibatalkan. QC tetap memeriksa sebelum menyetujui.
     */
    private function suggestedLines(WorkspaceDisbursement $row): array
    {
        $codes = collect($row->source_lines)->pluck('budget_code')->filter()->unique();
        $latest = [];
        foreach ($codes as $code) {
            $line = CashPaymentLine::query()->with('account:id,account_code,account_name,is_active,account_type',
                'department:id,code,name,is_active', 'project:id,code,name,is_active,status')
                ->where('metadata->workspace_budget_code', $code)
                ->whereHas('cashPayment', fn ($payment) => $payment->whereIn('status', ['draft', 'posted']))
                ->orderByDesc('id')->first();
            if ($line?->account && $line->account->is_active && $line->account->account_type === 'expense') {
                $latest[$code] = $line;
            }
        }

        return collect($row->source_lines)->map(function (array $source) use ($latest): array {
            $line = $latest[$source['budget_code'] ?? ''] ?? null;
            $option = fn ($model, string $code, string $name) => $model ? ['value' => $model->id, 'label' => $model->$code.' · '.$model->$name] : null;

            return [
                'source_line_id' => $source['id'],
                'account' => $option($line?->account, 'account_code', 'account_name'),
                'department' => $line?->department?->is_active ? $option($line->department, 'code', 'name') : null,
                'project' => $line?->project?->is_active && $line->project->status === 'active' ? $option($line->project, 'code', 'name') : null,
            ];
        })->all();
    }

    private function suggestedAdvanceAccount(): ?array
    {
        $account = Tranche::query()->whereNotNull('advance_account_id')->orderByDesc('id')->first()?->advanceAccount;

        return $account?->is_active ? ['value' => $account->id, 'label' => $account->account_code.' · '.$account->account_name] : null;
    }

    private function guardDate(string $date, string $action): void
    {
        $check = $this->dateGuard->check($date, $action, 'cash_bank');
        if ($check->denied()) {
            $arr = $check->toArray();
            throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']);
        }
    }

    private function locked(int $id, Closure $work): void
    {
        $db = DB::connection('tenant');
        $db->transaction(function () use ($db, $id, $work): void {
            if ($db->getDriverName() === 'sqlite') {
                $db->table('workspace_disbursements')->where('id', $id)->update(['id' => DB::raw('id')]);
            }
            WorkspaceDisbursement::query()->lockForUpdate()->findOrFail($id);
            $work($this->load($id));
        }, 3);
    }

    private function event(WorkspaceDisbursement $row, ?Tranche $tranche, string $action, int $actor, array $details): void
    {
        DB::connection('tenant')->table('workspace_disbursement_events')->insert([
            'workspace_disbursement_id' => $row->id,
            'tranche_id' => $tranche?->id,
            'action' => $action,
            'user_id' => $actor,
            'created_at' => now(),
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }

    private function minor(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function decimal(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
