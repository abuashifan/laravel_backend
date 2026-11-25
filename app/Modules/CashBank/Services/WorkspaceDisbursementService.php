<?php

namespace App\Modules\CashBank\Services;

use App\Modules\CashBank\Models\CashPayment;
use App\Modules\CashBank\Models\WorkspaceDisbursement;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Support\Facades\DB;

class WorkspaceDisbursementService
{
    public function __construct(
        private readonly CashPaymentService $cashPayments,
        private readonly CashBankAccountService $cashAccounts,
    ) {}

    public function index(array $filters): array
    {
        $rows = WorkspaceDisbursement::query()->with('cashPayment:id,payment_number,status,journal_entry_id')
            ->when(isset($filters['status']), fn ($query) => $query->where('qc_status', $filters['status']))
            ->orderByDesc('id')->paginate($filters['per_page'] ?? 25, [
                'id', 'workspace_document_id', 'unit_name', 'title', 'activity_date',
                'amount_minor', 'disbursed_on', 'qc_status', 'cash_payment_id',
            ], 'page', $filters['page'] ?? 1);

        return ['data' => $rows->items(), 'total' => $rows->total()];
    }

    public function show(int $id): WorkspaceDisbursement
    {
        $row = WorkspaceDisbursement::query()->with('cashPayment:id,payment_number,status,journal_entry_id', 'cashBankAccount:id,account_code,account_name')->findOrFail($id);
        $history = DB::connection('tenant')->table('workspace_disbursement_events')
            ->where('workspace_disbursement_id', $id)->orderBy('id')->get();
        $row->setAttribute('history', $history->map(fn ($event) => [
            'action' => $event->action, 'created_at' => $event->created_at, 'user_id' => $event->user_id,
            'details' => json_decode($event->details, true, 512, JSON_THROW_ON_ERROR),
        ]));

        return $row;
    }

    public function record(int $id, array $data, int $actor): WorkspaceDisbursement
    {
        $this->locked($id, function (WorkspaceDisbursement $row) use ($data, $actor): void {
            if ($row->qc_status === 'approved') {
                throw ApiException::make('WORKSPACE_QC_ALREADY_APPROVED', 'Pencairan sudah melewati QC. Buka kembali QC sebelum mengoreksi.', 409);
            }

            $data['cash_bank_account_id'] = (int) $data['cash_bank_account_id'];
            if (! $this->cashAccounts->isCashBankAccount($data['cash_bank_account_id'])
                || ! ChartOfAccount::query()->whereKey($data['cash_bank_account_id'])->where('is_active', true)->exists()) {
                throw ApiException::make('CASH_BANK_ACCOUNT_REQUIRED', 'Pilih akun kas atau bank yang aktif.', 422);
            }

            $before = [
                'disbursed_on' => $row->disbursed_on?->toDateString(),
                'cash_bank_account_id' => $row->cash_bank_account_id,
                'payment_reference' => $row->payment_reference,
            ];
            if ($before == $data) {
                return;
            }

            $row->update($data + ['disbursed_at' => now(), 'disbursed_by' => $actor]);
            $this->event($row, 'record_disbursement', $actor, ['previous' => $before, 'recorded' => $data]);
        });

        return $this->show($id);
    }

    public function review(int $id, string $decision, array $data, int $actor): WorkspaceDisbursement
    {
        $this->locked($id, function (WorkspaceDisbursement $row) use ($decision, $data, $actor): void {
            if ($row->qc_status === 'approved') {
                if ($decision === 'approve') {
                    return;
                }
                throw ApiException::make('WORKSPACE_QC_ALREADY_APPROVED', 'Buka kembali QC sebelum menolak draf yang sudah disetujui.', 409);
            }

            $note = $data['note'] ?? null;
            if ($decision === 'reject') {
                $row->update(['qc_status' => 'rejected', 'qc_note' => $note, 'qc_at' => now(), 'qc_by' => $actor]);
                $this->event($row, 'reject_qc', $actor, ['note' => $note]);

                return;
            }

            if ($row->disbursed_on === null) {
                throw ApiException::make('WORKSPACE_DISBURSEMENT_REQUIRED', 'Catat pencairan sebelum menyetujui QC.', 422);
            }

            $lines = $this->mapAccounts($row, $data['lines']);
            $payment = $this->cashPayments->create([
                'payment_date' => $row->disbursed_on->toDateString(),
                'cash_bank_account_id' => $row->cash_bank_account_id,
                'currency_code' => 'IDR',
                'exchange_rate' => 1,
                'amount' => $this->decimal($row->amount_minor),
                'notes' => $row->title.' · RAB Workspace #'.$row->workspace_document_id.' · '.$row->payment_reference,
                'metadata' => ['workspace_disbursement_id' => $row->id, 'workspace_document_id' => $row->workspace_document_id],
                'lines' => $lines,
            ]);
            $row->update(['qc_status' => 'approved', 'qc_note' => $note, 'qc_at' => now(), 'qc_by' => $actor, 'cash_payment_id' => $payment->id]);
            $this->event($row, 'approve_qc', $actor, ['note' => $note, 'cash_payment_id' => $payment->id, 'accounts' => $data['lines']]);
        });

        return $this->show($id);
    }

    public function reopen(int $id, string $note, int $actor): WorkspaceDisbursement
    {
        $this->locked($id, function (WorkspaceDisbursement $row) use ($note, $actor): void {
            $payment = CashPayment::query()->lockForUpdate()->find($row->cash_payment_id);
            if ($payment?->status !== 'draft') {
                throw ApiException::make('WORKSPACE_QC_CANNOT_REOPEN', 'QC dapat dibuka kembali saat kas keluar masih draf.', 409);
            }

            $row->update(['qc_status' => 'pending', 'qc_note' => null, 'qc_at' => null, 'qc_by' => null, 'cash_payment_id' => null]);
            $this->cashPayments->delete($payment, $note);
            $this->event($row, 'reopen_qc', $actor, ['note' => $note, 'previous_cash_payment_id' => $payment->id]);
        });

        return $this->show($id);
    }

    private function mapAccounts(WorkspaceDisbursement $row, array $decisions): array
    {
        $mapping = collect($decisions)->keyBy('source_line_id');
        if ($mapping->count() !== count($row->source_lines) || array_diff(array_column($row->source_lines, 'id'), $mapping->keys()->all())) {
            throw ApiException::make('WORKSPACE_DISBURSEMENT_LINE_MISMATCH', 'Pilih akun untuk setiap baris asli RAB.', 422);
        }

        $accountIds = $mapping->pluck('account_id')->unique();
        $expenses = ChartOfAccount::query()->whereIn('id', $accountIds)->where('is_active', true)
            ->where('account_type', 'expense')->count();
        if ($expenses !== $accountIds->count()) {
            throw ApiException::make('WORKSPACE_EXPENSE_ACCOUNT_REQUIRED', 'Setiap baris harus memakai akun biaya aktif.', 422);
        }

        return array_map(fn (array $line) => [
            'account_id' => $mapping[$line['id']]['account_id'],
            'department_id' => $mapping[$line['id']]['department_id'] ?? null,
            'project_id' => $mapping[$line['id']]['project_id'] ?? null,
            'amount' => $this->decimal((int) $line['amount_minor']),
            'description' => $line['description'],
            'metadata' => ['workspace_line_id' => $line['id'], 'workspace_budget_code' => $line['budget_code']],
        ], $row->source_lines);
    }

    private function locked(int $id, Closure $work): void
    {
        $db = DB::connection('tenant');
        $db->transaction(function () use ($db, $id, $work): void {
            if ($db->getDriverName() === 'sqlite') {
                $db->table('workspace_disbursements')->where('id', $id)->update(['id' => DB::raw('id')]);
            }

            $work(WorkspaceDisbursement::query()->lockForUpdate()->findOrFail($id));
        }, 3);
    }

    private function event(WorkspaceDisbursement $row, string $action, int $actor, array $details): void
    {
        DB::connection('tenant')->table('workspace_disbursement_events')->insert([
            'workspace_disbursement_id' => $row->id,
            'action' => $action,
            'user_id' => $actor,
            'created_at' => now(),
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }

    private function decimal(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
