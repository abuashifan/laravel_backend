<?php

namespace App\Modules\CashBank\Services;

use App\Modules\CashBank\Models\CashPayment;
use App\Modules\Journal\Models\JournalEntry;
use App\Shared\Api\AppliesListQuery;
use App\Shared\Api\AttachesCreatorNames;
use App\Shared\Audit\AuditLogService;
use App\Shared\DocumentNumbering\DocumentNumberService;
use App\Shared\DocumentNumbering\DocumentType;
use App\Shared\Exceptions\ApiException;
use App\Shared\Tenant\TenantContext;
use App\Shared\TransactionLifecycle\ChangesTransactionDocuments;
use App\Shared\TransactionLifecycle\TracksDocumentHistory;
use App\Shared\TransactionLifecycle\TransactionDateGuardService;
use App\Shared\TransactionLifecycle\TransactionDependents;
use App\Shared\TransactionLifecycle\TransactionModule;
use App\Shared\TransactionLifecycle\TransactionPolicyService;
use App\Shared\TransactionLifecycle\TransactionVoidEffectService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CashPaymentService
{
    use AppliesListQuery;
    use AttachesCreatorNames;
    use ChangesTransactionDocuments;
    use TracksDocumentHistory;

    /** `notes`, bukan `description` -- lihat catatan di CashReceiptService. */
    protected array $listSearchable = ['payment_number', 'notes'];

    protected array $listSearchableRelations = [];

    protected string $listDateColumn = 'payment_date';

    protected string $listStatusColumn = 'status';

    protected array $listDefaultSort = ['payment_date' => 'desc', 'id' => 'desc'];

    protected array $listSortable = ['payment_number', 'payment_date', 'amount', 'status', 'created_at'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly CashBankAccountService $cashBankAccountService,
        private readonly TransactionVoidEffectService $voidEffectService,
        private readonly ?AuditLogService $auditLogService = null,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator|Collection<int,CashPayment>
     */
    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $query = CashPayment::query()->with('contact', 'cashBankAccount');

        if (! empty($filters['cash_bank_account_id'])) {
            $query->where('cash_bank_account_id', (int) $filters['cash_bank_account_id']);
        }

        $result = $this->applyListQuery($query, $filters);
        $this->attachCreatorNames($result instanceof LengthAwarePaginator ? $result->getCollection() : $result);

        return $result;
    }

    public function find(int $id): CashPayment
    {
        return CashPayment::query()->with('lines.account', 'contact', 'cashBankAccount')->findOrFail($id);
    }

    public function create(array $data): CashPayment
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        $cashAccountId = (int) $data['cash_bank_account_id'];
        if (! $this->cashBankAccountService->isCashBankAccount($cashAccountId)) {
            throw ApiException::make('CASH_BANK_ACCOUNT_REQUIRED', 'Cash/bank account must be a cash/bank marked COA.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($company, $data) {
            $header = $data;
            unset($header['lines']);

            $payment = CashPayment::query()->create(array_merge($header, [
                'payment_number' => $this->documentNumberService->generate($company, DocumentType::CASH_PAYMENT, (string) $data['payment_date']),
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]));

            $lines = $data['lines'] ?? [];
            if ($lines === []) {
                throw ApiException::make('LINES_REQUIRED', 'Cash payment lines are required.', 422);
            }

            $sum = 0.0;
            foreach ($lines as $ln) {
                $sum += (float) ($ln['amount'] ?? 0);
            }

            if (abs($sum - (float) $data['amount']) > 0.01) {
                throw ApiException::make('AMOUNT_MISMATCH', 'Header amount must equal sum(lines.amount).', 422, [
                    'amount' => ['Header amount must equal sum of lines.'],
                ], ['lines_sum' => $sum]);
            }

            $payment->lines()->createMany(array_map(function ($ln) {
                return array_merge($ln, [
                    'line_order' => (int) ($ln['line_order'] ?? 1),
                ]);
            }, $lines));

            return $payment->refresh()->load('lines', 'contact', 'cashBankAccount');
        });
    }

    public function post(CashPayment $payment): CashPayment
    {
        if ($payment->status === 'posted') {
            return $payment;
        }
        $this->guardDate((string) $payment->payment_date);

        if (! $this->cashBankAccountService->isCashBankAccount((int) $payment->cash_bank_account_id)) {
            throw ApiException::make('CASH_BANK_ACCOUNT_REQUIRED', 'Cash/bank account must be a cash/bank marked COA.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($payment) {
            $payment->loadMissing('lines');
            if ($payment->lines->count() === 0) {
                throw ApiException::make('LINES_REQUIRED', 'Cash payment lines are required.', 422);
            }

            $journal = $this->journal($payment);
            $payment->status = 'posted';
            $payment->journal_entry_id = $journal->id;
            $payment->posted_by = auth()->id();
            $payment->posted_at = now();
            $payment->save();

            return $payment->refresh()->load('lines', 'contact', 'cashBankAccount');
        });
    }

    public function void(CashPayment $payment, ?string $reason = null): CashPayment
    {
        if ($payment->status === 'void') {
            throw ApiException::make('CASH_PAYMENT_ALREADY_VOID', 'Cash payment already void.', 422);
        }
        $reason = $this->voidEffectService->requireReason($reason);
        $this->guardDate((string) $payment->payment_date, 'void');

        return DB::connection('tenant')->transaction(function () use ($payment, $reason) {
            $journalIds = $this->voidEffectService->voidJournalsForSource('cash_payment', (int) $payment->id, $reason);
            $payment->status = 'void';
            $payment->voided_by = auth()->id();
            $payment->voided_at = now();
            $payment->void_reason = $reason;
            $payment->save();
            $this->auditLogService?->logSuccess(['event' => 'cash_bank.cash_payment_voided', 'module' => 'cash_bank', 'record_type' => 'cash_payment', 'record_id' => $payment->id, 'record_number' => $payment->payment_number, 'user_id' => auth()->id(), 'metadata' => ['reason' => $reason, 'voided_journal_ids' => $journalIds]], tenant: true);

            return $payment->refresh();
        });
    }

    /**
     * Edit kas keluar menurut mode lock/open. Draft diedit langsung; yang sudah
     * posted (mode open) jurnal lamanya di-void dulu, lalu diedit dan
     * diposting ulang dengan nomor yang sama.
     */
    public function update(CashPayment $payment, array $data): CashPayment
    {
        return $this->withDocumentHistory($payment, fn (): CashPayment => $this->applyUpdate($payment, $data));
    }

    private function applyUpdate(CashPayment $payment, array $data): CashPayment
    {
        $this->changePolicy()->assertDocumentChange(
            TransactionPolicyService::CHANGE_EDIT,
            TransactionModule::CASH_BANK,
            (string) $payment->status,
            $this->dependents($payment),
            $payment->payment_date?->toDateString(),
        );

        return DB::connection('tenant')->transaction(function () use ($payment, $data) {
            $wasPosted = $payment->status === 'posted';
            if ($wasPosted) {
                $this->guardDate((string) $payment->payment_date, 'void');
                $this->voidEffectService->voidJournalsForSource('cash_payment', (int) $payment->id, 'Diedit setelah posting');
                $payment->status = 'draft';
                $payment->journal_entry_id = null;
                $payment->posted_by = null;
                $payment->posted_at = null;
                $payment->save();
            }
            $payment = $this->updateDraft($payment->refresh(), $data);

            return $wasPosted ? $this->post($payment) : $payment;
        });
    }

    /**
     * Hapus kas keluar. Draft dihapus permanen; yang sudah posted di-void lalu
     * ditandai terhapus.
     */
    public function delete(CashPayment $payment, ?string $reason = null): void
    {
        $this->changePolicy()->assertDocumentChange(
            TransactionPolicyService::CHANGE_DELETE,
            TransactionModule::CASH_BANK,
            (string) $payment->status,
            $this->dependents($payment),
            $payment->payment_date?->toDateString(),
        );

        $reason = trim((string) $reason);

        DB::connection('tenant')->transaction(function () use ($payment, $reason): void {
            if ($payment->status === 'draft') {
                $payment->lines()->delete();
                $payment->forceDelete();

                return;
            }

            $voided = $this->void($payment, $this->deleteVoidReason($reason));
            $this->markVoidedDocumentDeleted($voided, $reason);
        });
    }

    /**
     * @return list<string>
     */
    public function dependents(CashPayment $payment): array
    {
        return app(TransactionDependents::class)->reconciledSource('cash_payment', (int) $payment->id);
    }

    private function updateDraft(CashPayment $payment, array $data): CashPayment
    {
        $cashAccountId = (int) ($data['cash_bank_account_id'] ?? $payment->cash_bank_account_id);
        if (! $this->cashBankAccountService->isCashBankAccount($cashAccountId)) {
            throw ApiException::make('CASH_BANK_ACCOUNT_REQUIRED', 'Cash/bank account must be a cash/bank marked COA.', 422);
        }

        $lines = $data['lines'] ?? $payment->lines()->get()->map(fn ($line) => $line->only(['account_id', 'amount', 'description', 'department_id', 'project_id', 'line_order']))->all();
        if ($lines === []) {
            throw ApiException::make('LINES_REQUIRED', 'Cash payment lines are required.', 422);
        }

        $amount = (float) ($data['amount'] ?? $payment->amount);
        $sum = 0.0;
        foreach ($lines as $ln) {
            $sum += (float) ($ln['amount'] ?? 0);
        }
        if (abs($sum - $amount) > 0.01) {
            throw ApiException::make('AMOUNT_MISMATCH', 'Header amount must equal sum(lines.amount).', 422, [
                'amount' => ['Header amount must equal sum of lines.'],
            ], ['lines_sum' => $sum]);
        }

        $header = $data;
        unset($header['lines'], $header['status']);
        if (trim((string) ($header['payment_number'] ?? '')) === '') {
            unset($header['payment_number']);
        }

        $payment->fill($header);
        $payment->save();
        $payment->lines()->delete();
        $payment->lines()->createMany(array_map(fn ($ln) => array_merge($ln, ['line_order' => (int) ($ln['line_order'] ?? 1)]), $lines));

        return $payment->refresh()->load('lines', 'contact', 'cashBankAccount');
    }

    private function journal(CashPayment $payment): JournalEntry
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        $payment->loadMissing('lines');
        $journal = JournalEntry::query()->create([
            'journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, (string) $payment->payment_date),
            'journal_date' => $payment->payment_date,
            'description' => 'Cash payment '.$payment->payment_number,
            'status' => 'posted',
            'revision_no' => 1,
            'source_type' => 'cash_payment',
            'source_id' => $payment->id,
            'source_number' => $payment->payment_number,
            'source_revision' => 1,
            'source_module' => 'cash_bank',
            'is_system_generated' => true,
            'created_by' => auth()->id(),
            'posted_by' => auth()->id(),
            'posted_at' => now(),
        ]);

        $lines = [];
        $order = 1;
        foreach ($payment->lines as $ln) {
            $lines[] = [
                'account_id' => (int) $ln->account_id,
                'description' => $ln->description ?? 'Expense/Offset',
                'debit' => (float) $ln->amount,
                'credit' => 0,
                'line_order' => $order++,
                'department_id' => $ln->department_id,
                'project_id' => $ln->project_id,
            ];
        }

        $lines[] = [
            'account_id' => (int) $payment->cash_bank_account_id,
            'description' => 'Cash/Bank',
            'debit' => 0,
            'credit' => (float) $payment->amount,
            'line_order' => $order,
        ];

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
}
