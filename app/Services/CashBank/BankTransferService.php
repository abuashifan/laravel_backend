<?php

namespace App\Services\CashBank;

use App\Exceptions\ApiException;
use App\Models\Tenant\BankTransfer;
use App\Models\Tenant\JournalEntry;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Tenant\TenantContext;
use App\Services\Transactions\TransactionDateGuardService;
use App\Services\Transactions\TransactionVoidEffectService;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BankTransferService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly CashBankAccountService $cashBankAccountService,
        private readonly TransactionVoidEffectService $voidEffectService,
        private readonly ?AuditLogService $auditLogService = null,
    ) {}

    public function list(array $filters = []): Collection
    {
        $query = BankTransfer::query()->with('fromCashBankAccount', 'toCashBankAccount');
        if (! empty($filters['statuses']) && is_array($filters['statuses'])) {
            $query->whereIn('status', array_map('strval', $filters['statuses']));
        } elseif (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('transfer_date', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('transfer_date', '<=', (string) $filters['date_to']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.str_replace('%', '', (string) $filters['search']).'%';
            $query->where(fn ($builder) => $builder->where('transfer_number', 'like', $term)->orWhere('notes', 'like', $term));
        }

        return $query->orderByDesc('transfer_date')->orderByDesc('id')->get();
    }

    public function find(int $id): BankTransfer
    {
        return BankTransfer::query()->with('fromCashBankAccount', 'toCashBankAccount')->findOrFail($id);
    }

    public function create(array $data): BankTransfer
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        $fromId = (int) $data['from_cash_bank_account_id'];
        $toId = (int) $data['to_cash_bank_account_id'];

        if (! $this->cashBankAccountService->isCashBankAccount($fromId) || ! $this->cashBankAccountService->isCashBankAccount($toId)) {
            throw ApiException::make('CASH_BANK_ACCOUNT_REQUIRED', 'Both from/to accounts must be cash/bank marked COA.', 422);
        }
        if ($fromId === $toId) {
            throw ApiException::make('SAME_ACCOUNT_NOT_ALLOWED', 'From and to cash/bank accounts must be different.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($company, $data) {
            $transfer = BankTransfer::query()->create(array_merge($data, [
                'transfer_number' => $this->documentNumberService->generate($company, DocumentType::BANK_TRANSFER, (string) $data['transfer_date']),
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]));

            return $transfer->refresh()->load('fromCashBankAccount', 'toCashBankAccount');
        });
    }

    public function update(BankTransfer $transfer, array $data): BankTransfer
    {
        if ($transfer->status !== 'draft') {
            throw ApiException::make('BANK_TRANSFER_NOT_EDITABLE', 'Only draft bank transfers can be updated.', 422);
        }

        $fromId = (int) $data['from_cash_bank_account_id'];
        $toId = (int) $data['to_cash_bank_account_id'];
        if (! $this->cashBankAccountService->isCashBankAccount($fromId) || ! $this->cashBankAccountService->isCashBankAccount($toId)) {
            throw ApiException::make('CASH_BANK_ACCOUNT_REQUIRED', 'Both from/to accounts must be active cash/bank marked COA.', 422);
        }
        if ($fromId === $toId) {
            throw ApiException::make('SAME_ACCOUNT_NOT_ALLOWED', 'From and to cash/bank accounts must be different.', 422, [
                'to_cash_bank_account_id' => ['The destination account must be different from the source account.'],
            ]);
        }

        $transfer->fill($data)->save();

        return $transfer->refresh()->load('fromCashBankAccount', 'toCashBankAccount');
    }

    public function post(BankTransfer $transfer): BankTransfer
    {
        if ($transfer->status === 'posted') {
            return $transfer;
        }
        $this->guardDate((string) $transfer->transfer_date);

        $fromId = (int) $transfer->from_cash_bank_account_id;
        $toId = (int) $transfer->to_cash_bank_account_id;

        if (! $this->cashBankAccountService->isCashBankAccount($fromId) || ! $this->cashBankAccountService->isCashBankAccount($toId)) {
            throw ApiException::make('CASH_BANK_ACCOUNT_REQUIRED', 'Both from/to accounts must be cash/bank marked COA.', 422);
        }
        if ($fromId === $toId) {
            throw ApiException::make('SAME_ACCOUNT_NOT_ALLOWED', 'From and to cash/bank accounts must be different.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($transfer) {
            $journal = $this->journal($transfer);
            $transfer->status = 'posted';
            $transfer->journal_entry_id = $journal->id;
            $transfer->posted_by = auth()->id();
            $transfer->posted_at = now();
            $transfer->save();

            return $transfer->refresh()->load('fromCashBankAccount', 'toCashBankAccount');
        });
    }

    public function void(BankTransfer $transfer, ?string $reason = null): BankTransfer
    {
        if ($transfer->status === 'void') {
            throw ApiException::make('BANK_TRANSFER_ALREADY_VOID', 'Bank transfer already void.', 422);
        }
        $reason = $this->voidEffectService->requireReason($reason);
        $this->guardDate((string) $transfer->transfer_date, 'void');

        return DB::connection('tenant')->transaction(function () use ($transfer, $reason) {
            $journalIds = $this->voidEffectService->voidJournalsForSource('bank_transfer', (int) $transfer->id, $reason);
            $transfer->status = 'void';
            $transfer->voided_by = auth()->id();
            $transfer->voided_at = now();
            $transfer->void_reason = $reason;
            $transfer->save();
            $this->auditLogService?->logSuccess(['event' => 'cash_bank.bank_transfer_voided', 'module' => 'cash_bank', 'record_type' => 'bank_transfer', 'record_id' => $transfer->id, 'record_number' => $transfer->transfer_number, 'user_id' => auth()->id(), 'metadata' => ['reason' => $reason, 'voided_journal_ids' => $journalIds]], tenant: true);

            return $transfer->refresh();
        });
    }

    private function journal(BankTransfer $transfer): JournalEntry
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        $journal = JournalEntry::query()->create([
            'journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, (string) $transfer->transfer_date),
            'journal_date' => $transfer->transfer_date,
            'description' => 'Bank transfer '.$transfer->transfer_number,
            'status' => 'posted',
            'revision_no' => 1,
            'source_type' => 'bank_transfer',
            'source_id' => $transfer->id,
            'source_number' => $transfer->transfer_number,
            'source_revision' => 1,
            'source_module' => 'cash_bank',
            'is_system_generated' => true,
            'created_by' => auth()->id(),
            'posted_by' => auth()->id(),
            'posted_at' => now(),
        ]);

        // MVP: treat cash/bank accounts as normal debit accounts (common case).
        // Dr to_cash_bank, Cr from_cash_bank.
        $journal->lines()->createMany([
            [
                'account_id' => (int) $transfer->to_cash_bank_account_id,
                'description' => 'Cash/Bank (To)',
                'debit' => (float) $transfer->amount,
                'credit' => 0,
                'line_order' => 1,
            ],
            [
                'account_id' => (int) $transfer->from_cash_bank_account_id,
                'description' => 'Cash/Bank (From)',
                'debit' => 0,
                'credit' => (float) $transfer->amount,
                'line_order' => 2,
            ],
        ]);

        return $journal->refresh();
    }

    private function guardDate(string $date, string $action = 'post'): void
    {
        $check = $this->dateGuardService->check($date, $action, 'cash_bank');
        if ($check->denied()) {
            $arr = $check->toArray();
            throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']);
        }
    }
}
