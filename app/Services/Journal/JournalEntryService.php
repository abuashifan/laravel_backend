<?php

namespace App\Services\Journal;

use App\Exceptions\ApiException;
use App\Models\Tenant\JournalEntry;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Settings\CompanySettingService;
use App\Services\Tenant\TenantContext;
use App\Services\Transactions\TransactionPolicyService;
use App\Services\Transactions\TransactionRevisionService;
use App\Support\Api\ApiErrorCode;
use App\Support\DocumentNumbering\DocumentType;
use App\Support\Transaction\TransactionModule;
use App\Support\Transaction\TransactionPolicyResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class JournalEntryService
{
    public function __construct(
        private readonly JournalValidationService $validator,
        private readonly JournalLineNormalizer $normalizer,
        private readonly JournalPostingService $postingService,
        private readonly JournalVoidService $voidService,
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly CompanySettingService $companySettingService,
        private readonly TransactionPolicyService $policyService,
        private readonly TransactionRevisionService $revisionService,
        private readonly ?AuditLogService $auditLogService = null,
    ) {}

    /**
     * @return Collection<int,JournalEntry>
     */
    public function list(array $filters = []): Collection
    {
        $query = JournalEntry::query();

        $includeVoid = filter_var($filters['include_void'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $includeVoid) {
            $query->where('status', '!=', 'void');
        }

        $includeObsolete = filter_var($filters['include_obsolete'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $includeObsolete) {
            $query->where('is_obsolete', false);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('journal_date', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('journal_date', '<=', (string) $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace('%', '', (string) $filters['search']).'%';
            $query->where(function ($q) use ($term) {
                $q->where('journal_number', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        if (array_key_exists('is_system_generated', $filters)
            && $filters['is_system_generated'] !== null
            && $filters['is_system_generated'] !== '') {
            $query->where('is_system_generated', filter_var($filters['is_system_generated'], FILTER_VALIDATE_BOOLEAN));
        }

        $query->withSum('lines as total_debit', 'debit')
            ->withSum('lines as total_credit', 'credit');

        return $query->orderByDesc('journal_date')->orderByDesc('id')->get();
    }

    public function find(int|string $id): JournalEntry
    {
        return JournalEntry::query()->with('lines.account')->findOrFail($id);
    }

    public function createManual(array $data): JournalEntry
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make(ApiErrorCode::COMPANY_NOT_FOUND, 'Company context not resolved.', 422);
        }

        $journalDate = (string) ($data['journal_date'] ?? null);
        $policy = $this->policyService->canCreate(TransactionModule::JOURNAL, $journalDate);
        if ($policy->denied()) {
            $this->throwFromPolicy($policy);
        }

        $lines = $this->normalizer->normalize((array) ($data['lines'] ?? []));
        $validation = $this->validator->validateLines($lines, requireActiveAccounts: true);
        if (! $validation['valid']) {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Journal validation failed.', 422, (array) ($validation['errors'] ?? []), [
                'totals' => $validation['totals'] ?? null,
            ]);
        }
        $controlValidation = $this->validator->validateNoControlAccounts($lines);
        if (! $controlValidation['valid']) {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Manual journal cannot use protected control accounts.', 422, (array) ($controlValidation['errors'] ?? []));
        }

        $journalNumber = $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, $journalDate);

        $userId = auth()->id();
        $workflow = $this->companySettingService->getOrCreateAccountingSetting($company);

        $shouldAutoPost = $workflow->transaction_workflow_mode === 'simple_auto_post' && (bool) $workflow->auto_post_transactions;

        return DB::transaction(function () use ($data, $lines, $journalNumber, $journalDate, $userId, $shouldAutoPost) {
            $journal = JournalEntry::query()->create([
                'journal_number' => $journalNumber,
                'journal_date' => $journalDate,
                'description' => $data['description'] ?? null,
                'status' => $shouldAutoPost ? 'posted' : 'draft',
                'revision_no' => 1,
                'source_type' => 'manual_journal',
                'source_id' => null,
                'source_number' => $journalNumber,
                'source_revision' => 1,
                'source_module' => 'journal',
                'source_batch_id' => null,
                'is_system_generated' => false,
                'is_obsolete' => false,
                'created_by' => $userId,
                'updated_by' => $userId,
                'posted_by' => $shouldAutoPost ? $userId : null,
                'posted_at' => $shouldAutoPost ? now() : null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            $journal->lines()->createMany($lines);

            $journal = $journal->refresh()->load('lines.account');

            $this->audit('journal.created', $journal, $userId, [
                'workflow' => $shouldAutoPost ? 'simple_auto_post' : 'draft',
            ]);

            if ($shouldAutoPost) {
                $this->audit('journal.posted', $journal, $userId);
            }

            return $journal;
        });
    }

    public function updateManual(JournalEntry $journal, array $data): JournalEntry
    {
        if ($journal->isSystemGenerated()) {
            throw ApiException::make(ApiErrorCode::SYSTEM_GENERATED_READ_ONLY, 'System-generated journal cannot be edited directly.', 422);
        }

        $policy = $this->policyService->canEdit(TransactionModule::JOURNAL, $journal);
        if ($policy->denied()) {
            $this->throwFromPolicy($policy);
        }

        if ($journal->isVoided()) {
            throw ApiException::make(ApiErrorCode::TRANSACTION_ALREADY_VOID, 'Journal is void and cannot be edited.', 422);
        }

        $editReason = $data['edit_reason'] ?? null;
        if ($journal->isPosted() && (! is_string($editReason) || trim($editReason) === '')) {
            throw ApiException::make(ApiErrorCode::EDIT_REASON_REQUIRED, 'Edit reason is required for editing posted journal.', 422);
        }

        $lines = $this->normalizer->normalize((array) ($data['lines'] ?? []));
        $validation = $this->validator->validateLines($lines, requireActiveAccounts: true);
        if (! $validation['valid']) {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Journal validation failed.', 422, (array) ($validation['errors'] ?? []), [
                'totals' => $validation['totals'] ?? null,
            ]);
        }
        $controlValidation = $this->validator->validateNoControlAccounts($lines);
        if (! $controlValidation['valid']) {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Manual journal cannot use protected control accounts.', 422, (array) ($controlValidation['errors'] ?? []));
        }

        $userId = auth()->id();
        $oldSnapshot = $this->snapshotForRevision($journal);

        return DB::transaction(function () use ($journal, $data, $lines, $userId, $editReason, $oldSnapshot) {
            $revisionFrom = $journal->currentRevision();
            $journal->incrementRevision();

            if (array_key_exists('journal_date', $data) && $data['journal_date']) {
                $journal->journal_date = (string) $data['journal_date'];
            }

            if (array_key_exists('description', $data)) {
                $journal->description = $data['description'];
            }

            $journal->edit_reason = $editReason;
            $journal->updated_by = $userId;
            $journal->source_revision = $journal->currentRevision();
            $journal->save();

            $journal->lines()->delete();
            $journal->lines()->createMany($lines);

            $journal = $journal->refresh()->load('lines.account');

            $newSnapshot = $this->snapshotForRevision($journal);
            $this->revisionService->recordEdit(
                'journal_entry',
                $journal->id,
                $journal->journal_number,
                'journal',
                $revisionFrom,
                $journal->currentRevision(),
                $oldSnapshot,
                $newSnapshot,
                $editReason,
                $userId
            );

            $this->audit('journal.updated', $journal, $userId, [
                'revision_from' => $revisionFrom,
                'revision_to' => $journal->currentRevision(),
            ]);

            return $journal;
        });
    }

    public function approve(JournalEntry $journal, ?int $userId = null): JournalEntry
    {
        $userId ??= auth()->id();

        $policy = $this->policyService->canApprove(TransactionModule::JOURNAL, $journal);
        if ($policy->denied()) {
            $this->throwFromPolicy($policy);
        }

        if ($journal->isVoided()) {
            throw ApiException::make(ApiErrorCode::TRANSACTION_ALREADY_VOID, 'Journal is void and cannot be approved.', 422);
        }

        if ($journal->isApproved() || $journal->isPosted()) {
            return $journal;
        }

        $lines = $journal->lines()->get()->toArray();
        $validation = $this->validator->validateLines($this->mapLinesForValidation($lines), requireActiveAccounts: false);
        if (! $validation['valid']) {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Journal must be balanced before approval.', 422, (array) ($validation['errors'] ?? []));
        }

        $journal->status = 'approved';
        $journal->approved_by = $userId;
        $journal->approved_at = now();
        $journal->save();

        $this->audit('journal.approved', $journal->refresh(), $userId);

        return $journal->refresh();
    }

    public function post(JournalEntry $journal, ?int $userId = null): JournalEntry
    {
        $userId ??= auth()->id();

        $policy = $this->policyService->canPost(TransactionModule::JOURNAL, $journal);
        if ($policy->denied()) {
            $this->throwFromPolicy($policy);
        }

        $company = $this->tenantContext->company();
        if ($company) {
            $workflow = $this->companySettingService->getOrCreateAccountingSetting($company);
            if ($workflow->transaction_workflow_mode === 'draft_approve_post' && ! $journal->isApproved()) {
                throw ApiException::make(ApiErrorCode::JOURNAL_REQUIRES_APPROVAL, 'Journal must be approved before posting.', 422);
            }
        }

        $journal = $this->postingService->post($journal, $userId);
        $this->audit('journal.posted', $journal, $userId);

        return $journal;
    }

    public function void(JournalEntry $journal, string $reason, ?int $userId = null): JournalEntry
    {
        $userId ??= auth()->id();

        $policy = $this->policyService->canVoid(TransactionModule::JOURNAL, $journal);
        if ($policy->denied()) {
            $this->throwFromPolicy($policy);
        }

        $company = $this->tenantContext->company();
        if ($company) {
            $workflow = $this->companySettingService->getOrCreateAccountingSetting($company);
            if ((bool) $workflow->require_void_reason && trim($reason) === '') {
                throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Void reason is required.', 422, [
                    'reason' => ['Void reason is required.'],
                ]);
            }
        }

        $oldSnapshot = $this->snapshotForRevision($journal);

        $journal = $this->voidService->void($journal, $reason, $userId);

        $this->revisionService->recordVoid(
            'journal_entry',
            $journal->id,
            $journal->journal_number,
            'journal',
            $journal->currentRevision(),
            $reason,
            $userId,
            $oldSnapshot
        );

        $this->audit('journal.voided', $journal, $userId, ['reason' => $reason]);

        return $journal;
    }

    private function audit(string $event, JournalEntry $journal, ?int $userId, array $meta = []): void
    {
        if (! $this->auditLogService) {
            return;
        }

        $this->auditLogService->logSuccess([
            'event' => $event,
            'module' => 'journal',
            'record_type' => 'journal_entry',
            'record_id' => (string) $journal->id,
            'record_number' => $journal->journal_number,
            'user_id' => $userId,
            'source_type' => $journal->source_type,
            'source_id' => $journal->source_id,
            'source_number' => $journal->source_number,
            'source_revision' => $journal->source_revision,
            'source_module' => $journal->source_module,
            'source_batch_id' => $journal->source_batch_id,
            'metadata' => $meta,
        ], tenant: true);
    }

    private function throwFromPolicy(TransactionPolicyResult $policy): never
    {
        $arr = $policy->toArray();
        $code = (string) ($arr['code'] ?? ApiErrorCode::UNKNOWN_ERROR);
        $message = (string) ($arr['message'] ?? $code);
        $reasons = (array) ($arr['reasons'] ?? []);
        $meta = (array) ($arr['meta'] ?? []);

        $status = $code === ApiErrorCode::PERMISSION_DENIED ? 403 : 422;

        throw ApiException::make($code, $message, $status, $reasons, $meta);
    }

    private function snapshotForRevision(JournalEntry $journal): array
    {
        $journal->loadMissing('lines');

        return [
            'journal' => $journal->only([
                'id',
                'journal_number',
                'journal_date',
                'description',
                'status',
                'revision_no',
                'source_type',
                'source_id',
                'source_number',
                'source_revision',
                'source_module',
                'source_batch_id',
                'is_system_generated',
                'is_obsolete',
            ]),
            'lines' => $journal->lines->map(function ($line) {
                return [
                    'account_id' => $line->account_id,
                    'department_id' => $line->department_id,
                    'project_id' => $line->project_id,
                    'description' => $line->description,
                    'debit' => (string) $line->debit,
                    'credit' => (string) $line->credit,
                    'line_order' => $line->line_order,
                ];
            })->values()->toArray(),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $lines
     * @return array<int,array<string,mixed>>
     */
    private function mapLinesForValidation(array $lines): array
    {
        return array_map(function ($line) {
            return [
                'account_id' => $line['account_id'] ?? null,
                'department_id' => $line['department_id'] ?? null,
                'project_id' => $line['project_id'] ?? null,
                'debit' => $line['debit'] ?? 0,
                'credit' => $line['credit'] ?? 0,
                'description' => $line['description'] ?? null,
                'line_order' => $line['line_order'] ?? null,
                'metadata' => $line['metadata'] ?? null,
            ];
        }, $lines);
    }
}
