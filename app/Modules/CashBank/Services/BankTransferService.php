<?php

namespace App\Modules\CashBank\Services;

use App\Modules\CashBank\Models\BankTransfer;
use App\Modules\Journal\Models\JournalEntry;
use App\Shared\Api\AppliesListQuery;
use App\Shared\Api\AttachesCreatorNames;
use App\Shared\Audit\AuditLogService;
use App\Shared\DocumentNumbering\DocumentNumberService;
use App\Shared\DocumentNumbering\DocumentType;
use App\Shared\Exceptions\ApiException;
use App\Shared\Tenant\TenantContext;
use App\Shared\TransactionLifecycle\ChangesTransactionDocuments;
use App\Shared\TransactionLifecycle\TransactionDateGuardService;
use App\Shared\TransactionLifecycle\TransactionDependents;
use App\Shared\TransactionLifecycle\TransactionModule;
use App\Shared\TransactionLifecycle\TransactionPolicyService;
use App\Shared\TransactionLifecycle\TransactionVoidEffectService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BankTransferService
{
    use AppliesListQuery;
    use AttachesCreatorNames;
    use ChangesTransactionDocuments;

    protected array $listSearchable = ['transfer_number', 'notes'];

    protected array $listSearchableRelations = [];

    protected string $listDateColumn = 'transfer_date';

    protected string $listStatusColumn = 'status';

    protected array $listDefaultSort = ['transfer_date' => 'desc', 'id' => 'desc'];

    protected array $listSortable = ['transfer_number', 'transfer_date', 'amount', 'status', 'created_at'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly CashBankAccountService $cashBankAccountService,
        private readonly TransactionVoidEffectService $voidEffectService,
        private readonly ?AuditLogService $auditLogService = null,
    ) {}

    /**
     * Sengaja tidak menerima `cash_bank_account_id` seperti tiga service Kas &
     * Bank lainnya: transfer punya DUA akun (`from_`/`to_`), jadi artinya
     * ambigu -- akun asal saja, tujuan saja, atau salah satu. Menunggu
     * keputusan pemilik produk, jangan ditebak.
     *
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator|Collection<int,BankTransfer>
     */
    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $query = BankTransfer::query()->with('fromCashBankAccount', 'toCashBankAccount');

        $result = $this->applyListQuery($query, $filters);
        $this->attachCreatorNames($result instanceof LengthAwarePaginator ? $result->getCollection() : $result);

        return $result;
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

    /**
     * Edit transfer bank menurut mode lock/open (`TransactionPolicyService`).
     * Draft diedit langsung; yang sudah posted (mode open) jurnal lamanya di-void lalu diposting ulang dengan nomor yang sama.
     */
    public function update(BankTransfer $transfer, array $data): BankTransfer
    {
        $this->changePolicy()->assertDocumentChange(
            TransactionPolicyService::CHANGE_EDIT,
            TransactionModule::CASH_BANK,
            (string) $transfer->status,
            $this->dependents($transfer),
            $transfer->transfer_date?->toDateString(),
        );

        if ($transfer->status === 'draft') {
            return $this->updateDraft($transfer, $data);
        }

        return DB::connection('tenant')->transaction(function () use ($transfer, $data) {
            $this->reopenToDraft($transfer, 'Diedit setelah posting (revisi '.((int) ($transfer->revision_no ?? 0) + 1).')');
            $transfer = $this->updateDraft($transfer->refresh(), $data);

            return $this->post($transfer);
        });
    }

    /**
     * Hapus transfer bank. Status tanpa efek dihapus permanen; yang sudah berefek
     * di-void lewat jalur void biasa lalu ditandai terhapus.
     */
    public function delete(BankTransfer $transfer, ?string $reason = null): void
    {
        $this->changePolicy()->assertDocumentChange(
            TransactionPolicyService::CHANGE_DELETE,
            TransactionModule::CASH_BANK,
            (string) $transfer->status,
            $this->dependents($transfer),
            $transfer->transfer_date?->toDateString(),
        );

        $reason = trim((string) $reason);

        DB::connection('tenant')->transaction(function () use ($transfer, $reason): void {
            if (in_array($transfer->status, ['draft'], true)) {
                $this->auditLogService?->logSuccess(['event' => 'cash_bank.bank_transfer_deleted', 'module' => 'cash_bank', 'record_type' => 'bank_transfer', 'record_id' => $transfer->id, 'record_number' => $transfer->transfer_number, 'user_id' => auth()->id(), 'metadata' => ['reason' => $reason, 'permanent' => true]], tenant: true);
                if (method_exists($transfer, 'lines')) {
                    $transfer->lines()->delete();
                }
                $transfer->forceDelete();

                return;
            }

            $voided = $this->void($transfer, $this->deleteVoidReason($reason));
            $this->markVoidedDocumentDeleted($voided, $reason);
            $this->auditLogService?->logSuccess(['event' => 'cash_bank.bank_transfer_deleted', 'module' => 'cash_bank', 'record_type' => 'bank_transfer', 'record_id' => $voided->id, 'record_number' => $voided->transfer_number, 'user_id' => auth()->id(), 'metadata' => ['reason' => $reason, 'permanent' => false]], tenant: true);
        });
    }

    /**
     * Transaksi lain yang masih bergantung pada transfer bank ini.
     *
     * @return list<string>
     */
    public function dependents(BankTransfer $transfer): array
    {
        return app(TransactionDependents::class)->reconciledSource('bank_transfer', (int) $transfer->id);
    }

    private function reopenToDraft(BankTransfer $transfer, string $reason): void
    {
        $this->guardDate((string) $transfer->transfer_date, 'void');
        $this->voidEffectService->voidJournalsForSource('bank_transfer', (int) $transfer->id, $reason);
        $transfer->status = 'draft';
        $transfer->journal_entry_id = null;
        $transfer->posted_by = null;
        $transfer->posted_at = null;
        $transfer->save();
        $this->auditLogService?->logSuccess(['event' => 'cash_bank.bank_transfer_reopened', 'module' => 'cash_bank', 'record_type' => 'bank_transfer', 'record_id' => $transfer->id, 'record_number' => $transfer->transfer_number, 'user_id' => auth()->id(), 'metadata' => ['reason' => $reason]], tenant: true);
    }

    private function updateDraft(BankTransfer $transfer, array $data): BankTransfer
    {
        $fromId = (int) ($data['from_cash_bank_account_id'] ?? $transfer->from_cash_bank_account_id);
        $toId = (int) ($data['to_cash_bank_account_id'] ?? $transfer->to_cash_bank_account_id);

        if (! $this->cashBankAccountService->isCashBankAccount($fromId) || ! $this->cashBankAccountService->isCashBankAccount($toId)) {
            throw ApiException::make('CASH_BANK_ACCOUNT_REQUIRED', 'Both from/to accounts must be cash/bank marked COA.', 422);
        }
        if ($fromId === $toId) {
            throw ApiException::make('SAME_ACCOUNT_NOT_ALLOWED', 'From and to cash/bank accounts must be different.', 422);
        }

        $header = $data;
        unset($header['transfer_number'], $header['status']);
        $transfer->fill($header);
        $transfer->save();

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
