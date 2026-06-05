<?php

namespace App\Services\CashBank;

use App\Exceptions\ApiException;
use App\Models\Tenant\CashReceipt;
use App\Models\Tenant\JournalEntry;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Tenant\TenantContext;
use App\Services\Transactions\TransactionDateGuardService;
use App\Services\Transactions\TransactionVoidEffectService;
use App\Services\Audit\AuditLogService;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CashReceiptService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly CashBankAccountService $cashBankAccountService,
        private readonly TransactionVoidEffectService $voidEffectService,
        private readonly ?AuditLogService $auditLogService = null,
    ) {
    }

    public function list(array $filters = []): Collection
    {
        $query = CashReceipt::query()->with('contact', 'cashBankAccount');
        if (! empty($filters['status'])) $query->where('status', (string) $filters['status']);
        return $query->orderByDesc('receipt_date')->orderByDesc('id')->get();
    }

    public function find(int $id): CashReceipt
    {
        return CashReceipt::query()->with('lines', 'contact', 'cashBankAccount')->findOrFail($id);
    }

    public function create(array $data): CashReceipt
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);

        $cashAccountId = (int) $data['cash_bank_account_id'];
        if (! $this->cashBankAccountService->isCashBankAccount($cashAccountId)) {
            throw ApiException::make('CASH_BANK_ACCOUNT_REQUIRED', 'Cash/bank account must be a cash/bank marked COA.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($company, $data) {
            $header = $data;
            unset($header['lines']);

            $receipt = CashReceipt::query()->create(array_merge($header, [
                'receipt_number' => $this->documentNumberService->generate($company, DocumentType::CASH_RECEIPT, (string) $data['receipt_date']),
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]));

            $lines = $data['lines'] ?? [];
            if ($lines === []) {
                throw ApiException::make('LINES_REQUIRED', 'Cash receipt lines are required.', 422);
            }

            $sum = 0.0;
            foreach ($lines as $ln) { $sum += (float) ($ln['amount'] ?? 0); }

            if (abs($sum - (float) $data['amount']) > 0.01) {
                throw ApiException::make('AMOUNT_MISMATCH', 'Header amount must equal sum(lines.amount).', 422, [
                    'amount' => ['Header amount must equal sum of lines.'],
                ], ['lines_sum' => $sum]);
            }

            $receipt->lines()->createMany(array_map(function ($ln) {
                return array_merge($ln, [
                    'line_order' => (int) ($ln['line_order'] ?? 1),
                ]);
            }, $lines));

            return $receipt->refresh()->load('lines', 'contact', 'cashBankAccount');
        });
    }

    public function post(CashReceipt $receipt): CashReceipt
    {
        if ($receipt->status === 'posted') return $receipt;
        $this->guardDate((string) $receipt->receipt_date);

        if (! $this->cashBankAccountService->isCashBankAccount((int) $receipt->cash_bank_account_id)) {
            throw ApiException::make('CASH_BANK_ACCOUNT_REQUIRED', 'Cash/bank account must be a cash/bank marked COA.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($receipt) {
            $receipt->loadMissing('lines');
            if ($receipt->lines->count() === 0) {
                throw ApiException::make('LINES_REQUIRED', 'Cash receipt lines are required.', 422);
            }

            $journal = $this->journal($receipt);
            $receipt->status = 'posted';
            $receipt->journal_entry_id = $journal->id;
            $receipt->posted_by = auth()->id();
            $receipt->posted_at = now();
            $receipt->save();

            return $receipt->refresh()->load('lines', 'contact', 'cashBankAccount');
        });
    }

    public function void(CashReceipt $receipt, ?string $reason = null): CashReceipt
    {
        if ($receipt->status === 'void') throw ApiException::make('CASH_RECEIPT_ALREADY_VOID', 'Cash receipt already void.', 422);
        $reason = $this->voidEffectService->requireReason($reason);
        $this->guardDate((string) $receipt->receipt_date, 'void');
        return DB::connection('tenant')->transaction(function () use ($receipt, $reason) {
            $journalIds = $this->voidEffectService->voidJournalsForSource('cash_receipt', (int) $receipt->id, $reason);
            $receipt->status = 'void'; $receipt->voided_by = auth()->id(); $receipt->voided_at = now(); $receipt->void_reason = $reason; $receipt->save();
            $this->auditVoid('cash_receipt', $receipt->id, $receipt->receipt_number, $reason, $journalIds);
            return $receipt->refresh();
        });
    }

    private function journal(CashReceipt $receipt): JournalEntry
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);

        $receipt->loadMissing('lines');
        $journal = JournalEntry::query()->create([
            'journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, (string) $receipt->receipt_date),
            'journal_date' => $receipt->receipt_date,
            'description' => 'Cash receipt '.$receipt->receipt_number,
            'status' => 'posted',
            'revision_no' => 1,
            'source_type' => 'cash_receipt',
            'source_id' => $receipt->id,
            'source_number' => $receipt->receipt_number,
            'source_revision' => 1,
            'source_module' => 'cash_bank',
            'is_system_generated' => true,
            'created_by' => auth()->id(),
            'posted_by' => auth()->id(),
            'posted_at' => now(),
        ]);

        $lines = [];
        $lines[] = [
            'account_id' => (int) $receipt->cash_bank_account_id,
            'description' => 'Cash/Bank',
            'debit' => $receipt->amount,
            'credit' => 0,
            'line_order' => 1,
        ];

        $order = 2;
        foreach ($receipt->lines as $ln) {
            $lines[] = [
                'account_id' => (int) $ln->account_id,
                'description' => $ln->description ?? 'Offset',
                'debit' => 0,
                'credit' => (float) $ln->amount,
                'line_order' => $order++,
                'department_id' => $ln->department_id,
                'project_id' => $ln->project_id,
            ];
        }

        $journal->lines()->createMany($lines);
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

    private function auditVoid(string $type, int $id, string $number, string $reason, array $journalIds): void
    {
        $this->auditLogService?->logSuccess(['event' => 'cash_bank.'.$type.'_voided', 'module' => 'cash_bank', 'record_type' => $type, 'record_id' => $id, 'record_number' => $number, 'user_id' => auth()->id(), 'metadata' => ['reason' => $reason, 'voided_journal_ids' => $journalIds]], tenant: true);
    }
}
