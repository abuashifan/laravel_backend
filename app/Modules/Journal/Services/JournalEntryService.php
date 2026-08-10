<?php

namespace App\Modules\Journal\Services;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\Settings\Services\CompanySettingService;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Api\AppliesListQuery;
use App\Shared\Audit\AuditLogService;
use App\Shared\DocumentNumbering\DocumentNumberService;
use App\Shared\DocumentNumbering\DocumentType;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\User;
use App\Shared\Tenant\TenantContext;
use App\Shared\TransactionLifecycle\TransactionModule;
use App\Shared\TransactionLifecycle\TransactionPolicyResult;
use App\Shared\TransactionLifecycle\TransactionPolicyService;
use App\Shared\TransactionLifecycle\TransactionRevisionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class JournalEntryService
{
    use AppliesListQuery;

    protected array $listSearchable = ['journal_number', 'description'];

    protected array $listSearchableRelations = [];

    protected string $listDateColumn = 'journal_date';

    protected string $listStatusColumn = 'status';

    protected array $listDefaultSort = ['journal_date' => 'desc', 'id' => 'desc'];

    /**
     * `total_debit`/`total_credit` bukan kolom tabel -- keduanya alias dari
     * `withSum()` di `list()`. Alias itu ikut ke SELECT, jadi `ORDER BY
     * total_debit` valid di SQLite maupun MySQL. Tanpa alias tersebut, sorting
     * nominal di UI daftar tidak mungkin dilakukan tanpa menarik seluruh baris
     * jurnal ke PHP.
     */
    protected array $listSortable = ['journal_number', 'journal_date', 'status', 'created_at', 'total_debit', 'total_credit'];

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
     * Mengembalikan `LengthAwarePaginator` saat `page`/`per_page` dikirim, atau
     * `Collection` tanpa paginasi saat tidak -- lihat kontrak di
     * `AppliesListQuery::applyListQuery()`.
     *
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator|Collection<int,JournalEntry>
     */
    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        // Total debit/kredit dihitung di SQL sebagai agregat per jurnal.
        // Sebelumnya daftar tidak pernah mengirim angka ini (dan tidak memuat
        // lines), sehingga kolom Total Debit/Kredit di UI selalu "-" dan tidak
        // bisa diurutkan. Alias `total_debit`/`total_credit` sengaja dipakai
        // supaya sama dengan nama field pada response detail.
        $query = JournalEntry::query()
            ->withSum('lines as total_debit', 'debit')
            ->withSum('lines as total_credit', 'credit');

        // include_void/include_obsolete tetap di sini -- keduanya bukan bagian
        // dari kontrak search/status/tanggal/sort/paginate yang dipindahkan ke
        // AppliesListQuery.
        //
        // Exclusion "sembunyikan void" HANYA berlaku saat user tidak memfilter
        // status sama sekali. Begitu user memfilter status secara eksplisit --
        // termasuk memilih status=void di UI, yang TIDAK pernah mengirim
        // include_void=true -- filter itu yang harus menang. Sebelumnya
        // exclusion ini diterapkan tanpa syarat, sehingga where('status','!=',
        // 'void') AND status='void' (dari AppliesListQuery) jadi kontradiksi
        // yang selalu 0 hasil: user tidak bisa melihat jurnal yang sudah
        // di-void lewat filter status, walau baru saja mereka void sendiri.
        $includeVoid = filter_var($filters['include_void'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $hasStatusFilter = ! empty($filters['status']);
        if (! $includeVoid && ! $hasStatusFilter) {
            $query->where('status', '!=', 'void');
        }

        $includeObsolete = filter_var($filters['include_obsolete'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $includeObsolete) {
            $query->where('is_obsolete', false);
        }

        // Halaman Jurnal Umum hanya menampilkan jurnal manual dan sudah lama
        // mengirim `is_system_generated=false`, tapi filternya tidak pernah
        // diterapkan -- jurnal hasil posting Sales/Purchase/Inventory ikut
        // muncul dan bisa ikut terpilih pada aksi massal, padahal jurnal
        // sistem tidak boleh disunting/di-void langsung.
        $systemGenerated = $filters['is_system_generated'] ?? null;
        if ($systemGenerated !== null && $systemGenerated !== '') {
            $query->where('is_system_generated', filter_var($systemGenerated, FILTER_VALIDATE_BOOLEAN));
        }

        // Filter jenis jurnal: satu `source_type`, atau beberapa dipisah koma --
        // pola yang sama dengan `status` di AppliesListQuery. Daftar Jurnal Umum
        // memuat jurnal dari semua modul, jadi tanpa filter ini user tidak punya
        // cara mempersempit ke satu jenis saja (mis. hanya depresiasi aset
        // tetap); menyaring per modul lewat `source_module` terlalu kasar karena
        // satu modul menerbitkan banyak jenis jurnal sekaligus.
        //
        // Nilainya masuk lewat `whereIn` (parameter terikat), dan sengaja tidak
        // dibatasi allowlist `config('source_links.source_types')`: jurnal lama
        // dan data seed memakai source_type di luar daftar itu, dan tetap harus
        // bisa disaring bila pemanggil tahu nilainya.
        $sourceTypes = array_values(array_filter(
            array_map('trim', explode(',', (string) ($filters['source_type'] ?? ''))),
            fn (string $sourceType): bool => $sourceType !== '',
        ));
        if ($sourceTypes !== []) {
            $query->whereIn('source_type', $sourceTypes);
        }

        $result = $this->applyListQuery($query, $filters);

        $this->attachCreatorNames($result instanceof LengthAwarePaginator ? $result->getCollection() : $result);

        return $result;
    }

    /**
     * Lampirkan nama pembuat jurnal sebagai `created_by_name`.
     *
     * `journal_entries` ada di database tenant sedangkan `users` di database
     * pusat, jadi relasi Eloquent lintas koneksi tidak bisa di-eager-load.
     * Nama diambil sekali untuk seluruh halaman (satu query, bukan N+1) lalu
     * dipetakan ke tiap baris. Nilainya `null` bila jurnal dibuat sistem atau
     * user-nya sudah dihapus.
     *
     * @param  iterable<int,JournalEntry>  $journals
     */
    private function attachCreatorNames(iterable $journals): void
    {
        $ids = collect($journals)
            ->pluck('created_by')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $names = $ids === []
            ? []
            : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();

        foreach ($journals as $journal) {
            $createdBy = $journal->created_by;
            $journal->setAttribute('created_by_name', $createdBy ? ($names[$createdBy] ?? null) : null);
        }
    }

    public function find(int|string $id): JournalEntry
    {
        $journal = JournalEntry::query()->with('lines.account')->findOrFail($id);
        $this->attachCreatorNames([$journal]);

        return $journal;
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
