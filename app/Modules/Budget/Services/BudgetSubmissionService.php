<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\BudgetLine;
use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Models\BudgetSubmission;
use App\Modules\Budget\Support\BudgetDirection;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Project;
use App\Modules\Settings\Services\CompanySettingService;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\AuditLogService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BudgetSubmissionService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CompanySettingService $companySettingService,
        // WAJIB, bukan `?AuditLogService = null`. Pola nullable-with-default yang
        // dipakai JournalEntryService/CashBank TIDAK pernah terisi container —
        // parameter dengan nilai default diisi default-nya, sehingga audit di
        // modul-modul itu diam-diam tidak pernah menulis. Diverifikasi langsung
        // lewat refleksi. Modul ini memakai pola FixedAssetService/PeriodEndService
        // yang terbukti terinjeksi.
        private readonly AuditLogService $auditLogService,
    ) {}

    private function audit(string $event, BudgetSubmission $submission, array $meta = []): void
    {
        $this->auditLogService->logSuccess([
            'event' => $event,
            'module' => 'budget',
            'record_type' => 'budget_submission',
            'record_id' => (string) $submission->id,
            'user_id' => auth()->id(),
            'metadata' => $meta + [
                'version_no' => $submission->version_no,
                'department_id' => $submission->department_id,
            ],
        ], tenant: true);
    }

    /** @return Collection<int,BudgetSubmission> */
    public function list(BudgetPeriod $period, array $filters = []): Collection
    {
        $companyId = $this->tenantContext->companyId();
        $query = BudgetSubmission::query()
            ->forCompany($companyId)
            ->where('budget_period_id', $period->id)
            ->with('department');

        if (! empty($filters['department_id'])) {
            $query->where('department_id', (int) $filters['department_id']);
        }

        return $query->orderBy('department_id')->get();
    }

    public function create(BudgetPeriod $period, array $data): BudgetSubmission
    {
        $companyId = $this->tenantContext->companyId();
        $departmentId = isset($data['department_id']) ? (int) $data['department_id'] : null;

        $existing = BudgetSubmission::query()
            ->forCompany($companyId)
            ->where('budget_period_id', $period->id)
            // `where('department_id', null)` menghasilkan `= NULL` yang tidak pernah
            // cocok, jadi anggaran tingkat perusahaan harus dicocokkan eksplisit —
            // tanpa ini dua anggaran perusahaan bisa dibuat untuk periode yang sama.
            ->when(
                $departmentId === null,
                fn ($q) => $q->whereNull('department_id'),
                fn ($q) => $q->where('department_id', $departmentId),
            )
            // Versi yang sudah digantikan tidak menghalangi pembuatan berikutnya.
            ->where('status', '!=', 'superseded')
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                $departmentId === null
                    ? 'A company-level submission already exists for this period.'
                    : 'A submission already exists for this department in this period.',
                422
            );
        }

        return DB::transaction(function () use ($period, $data, $companyId, $departmentId) {
            $submission = BudgetSubmission::query()->create([
                'company_id' => $companyId,
                'budget_period_id' => $period->id,
                'department_id' => $departmentId,
                'status' => 'draft',
                'revision_number' => 1,
                'version_no' => 1,
                'is_active' => false,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->audit(AuditEvent::BUDGET_SUBMISSION_CREATED, $submission);

            return $submission;
        });
    }

    public function find(int $id): BudgetSubmission
    {
        $companyId = $this->tenantContext->companyId();

        return BudgetSubmission::query()
            ->forCompany($companyId)
            ->with(['period', 'department', 'lines.account', 'lines.department', 'lines.project'])
            ->findOrFail($id);
    }

    public function update(BudgetSubmission $submission, array $data): BudgetSubmission
    {
        $this->assertEditable($submission);

        $submission->update([
            'notes' => $data['notes'] ?? $submission->notes,
        ]);

        $this->audit(AuditEvent::BUDGET_SUBMISSION_UPDATED, $submission);

        return $submission->refresh();
    }

    public function updateLines(BudgetSubmission $submission, array $lines): BudgetSubmission
    {
        $this->assertEditable($submission);

        $normalized = $this->normalizeLines($submission, $lines);
        $this->validateLinesUnique($normalized);

        return DB::transaction(function () use ($submission, $normalized) {
            $submission->lines()->delete();

            $now = now();
            $toInsert = array_map(fn ($line) => $line + [
                'budget_submission_id' => $submission->id,
                'created_at' => $now,
                'updated_at' => $now,
            ], $normalized);

            BudgetLine::query()->insert($toInsert);

            $this->audit(AuditEvent::BUDGET_SUBMISSION_LINES_UPDATED, $submission, [
                'line_count' => count($toInsert),
                'total_amount' => number_format(array_sum(array_map(fn ($l) => (float) $l['amount'], $toInsert)), 2, '.', ''),
            ]);

            return $submission->load('lines.account', 'lines.department', 'lines.project');
        });
    }

    /**
     * Ubah payload mentah jadi baris siap simpan: warisi dimensi departemen dari
     * pemilik dokumen bila tidak disebut, terima nama kolom lama `period`, dan
     * turunkan `direction` dari jenis akun.
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @return array<int,array<string,mixed>>
     */
    private function normalizeLines(BudgetSubmission $submission, array $lines): array
    {
        $accountIds = array_values(array_unique(array_map(
            fn ($line) => (int) ($line['account_id'] ?? 0),
            $lines,
        )));

        /** @var array<int,string> $accountTypes */
        $accountTypes = ChartOfAccount::query()
            ->whereIn('id', $accountIds)
            ->pluck('account_type', 'id')
            ->all();

        $usableProjectIds = $this->usableProjectIds($lines);

        return array_map(function ($line, $index) use ($submission, $accountTypes, $usableProjectIds) {
            $accountId = (int) $line['account_id'];
            $direction = BudgetDirection::fromAccountType($accountTypes[$accountId] ?? null);

            if ($direction === null) {
                throw ApiException::make(
                    ApiErrorCode::BUDGET_ACCOUNT_DIRECTION_MISMATCH,
                    'Hanya akun pendapatan atau beban yang bisa dianggarkan.',
                    422,
                    ["lines.{$index}.account_id" => ['Hanya akun pendapatan atau beban yang bisa dianggarkan.']],
                );
            }

            // Anggaran 0 sah — ia menandai "tidak boleh belanja". Negatif tidak:
            // penurunan anggaran dilakukan lewat revisi, bukan nominal negatif,
            // supaya jejaknya terbaca.
            if ((float) $line['amount'] < 0) {
                throw ApiException::make(
                    ApiErrorCode::BUDGET_NEGATIVE_AMOUNT,
                    'Nominal anggaran tidak boleh negatif. Turunkan lewat revisi anggaran.',
                    422,
                    ["lines.{$index}.amount" => ['Nominal anggaran tidak boleh negatif.']],
                );
            }

            $projectId = isset($line['project_id']) ? (int) $line['project_id'] : null;
            if ($projectId !== null && ! in_array($projectId, $usableProjectIds, true)) {
                // Anggaran BARU untuk proyek selesai ditolak; anggaran lama tetap
                // terbaca dan tetap dibandingkan dengan actual.
                throw ApiException::make(
                    ApiErrorCode::BUDGET_PROJECT_NOT_ACTIVE,
                    'Proyek sudah tidak aktif sehingga tidak bisa dianggarkan.',
                    422,
                    ["lines.{$index}.project_id" => ['Proyek sudah tidak aktif.']],
                );
            }

            return [
                'account_id' => $accountId,
                // Key tidak dikirim = warisi departemen pemilik dokumen (default
                // yang paling sering benar). Kirim `null` eksplisit untuk baris
                // yang memang lintas departemen.
                'department_id' => array_key_exists('department_id', $line)
                    ? ($line['department_id'] === null ? null : (int) $line['department_id'])
                    : $submission->department_id,
                'project_id' => isset($line['project_id']) ? (int) $line['project_id'] : null,
                'period_month' => $line['period_month'] ?? $line['period'] ?? null,
                'direction' => $direction,
                'amount' => $line['amount'],
                'notes' => $line['notes'] ?? null,
            ];
        }, $lines, array_keys($lines));
    }

    public function submit(BudgetSubmission $submission): BudgetSubmission
    {
        $this->assertEditable($submission);

        $company = $this->tenantContext->company();
        $workflow = $this->companySettingService->getOrCreateAccountingSetting($company);
        $autoPost = $workflow->transaction_workflow_mode === 'simple_auto_post' && (bool) $workflow->auto_post_transactions;

        return DB::transaction(function () use ($submission, $autoPost) {
            $userId = auth()->id();
            $now = now();

            if ($autoPost) {
                $submission->update([
                    'status' => 'approved',
                    'submitted_by_id' => $userId,
                    'submitted_at' => $now,
                    'approved_by_finance_id' => $userId,
                    'approved_by_finance_at' => $now,
                ]);
                $this->markActiveVersion($submission);
                $this->audit(AuditEvent::BUDGET_SUBMISSION_APPROVED, $submission, ['auto_post' => true]);
            } else {
                $submission->update([
                    'status' => 'submitted',
                    'submitted_by_id' => $userId,
                    'submitted_at' => $now,
                ]);
            }

            $this->audit(AuditEvent::BUDGET_SUBMISSION_SUBMITTED, $submission);

            return $submission->refresh();
        });
    }

    public function approveHead(BudgetSubmission $submission): BudgetSubmission
    {
        if ($submission->status !== 'submitted') {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Submission must be in submitted status to approve as head.', 422);
        }

        $submission->update([
            'status' => 'approved_by_head',
            'approved_by_head_id' => auth()->id(),
            'approved_by_head_at' => now(),
        ]);

        $this->audit(AuditEvent::BUDGET_SUBMISSION_APPROVED_HEAD, $submission);

        return $submission->refresh();
    }

    public function approveFinance(BudgetSubmission $submission): BudgetSubmission
    {
        if ($submission->status !== 'approved_by_head') {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Submission must be approved by head first.', 422);
        }

        $submission->update([
            'status' => 'approved',
            'approved_by_finance_id' => auth()->id(),
            'approved_by_finance_at' => now(),
        ]);

        $this->markActiveVersion($submission);
        $this->audit(AuditEvent::BUDGET_SUBMISSION_APPROVED, $submission);

        return $submission->refresh();
    }

    /**
     * Tepat satu versi aktif per (periode, departemen). Inilah versi yang dibaca
     * laporan dan peringatan over-budget — tanpa penanda ini, `version=active`
     * di mesin analisis tidak akan pernah menemukan apa pun.
     *
     * Hari ini `create()` hanya mengizinkan satu submission per (periode,
     * departemen) non-superseded, jadi penonaktifan versi lain praktis tidak
     * pernah menemukan baris. Ia ditulis di sini karena alur revisi di fase 5
     * bersandar pada invarian yang sama.
     */
    private function markActiveVersion(BudgetSubmission $submission): void
    {
        BudgetSubmission::query()
            ->forCompany($submission->company_id)
            ->where('budget_period_id', $submission->budget_period_id)
            ->when(
                $submission->department_id === null,
                fn ($q) => $q->whereNull('department_id'),
                fn ($q) => $q->where('department_id', $submission->department_id),
            )
            ->whereKeyNot($submission->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $submission->forceFill(['is_active' => true])->save();
    }

    public function reject(BudgetSubmission $submission, string $rejectionNote): BudgetSubmission
    {
        $allowedStatuses = ['submitted', 'approved_by_head'];
        if (! in_array($submission->status, $allowedStatuses, true)) {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Submission cannot be rejected from its current status.', 422);
        }

        $submission->update([
            'status' => 'draft',
            'revision_number' => $submission->revision_number + 1,
            'rejected_by_id' => auth()->id(),
            'rejected_at' => now(),
            'rejection_note' => $rejectionNote,
            // Reset approval fields
            'approved_by_head_id' => null,
            'approved_by_head_at' => null,
            'approved_by_finance_id' => null,
            'approved_by_finance_at' => null,
        ]);

        $this->audit(AuditEvent::BUDGET_SUBMISSION_REJECTED, $submission, [
            'rejection_note' => $rejectionNote,
            'revision_number' => $submission->revision_number,
        ]);

        return $submission->refresh();
    }

    private function assertEditable(BudgetSubmission $submission): void
    {
        if (! in_array($submission->status, ['draft', 'rejected'], true)) {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Submission can only be edited in draft or rejected status.', 422);
        }
    }

    /**
     * Proyek yang boleh dianggarkan: aktif **dan** berstatus active. Diambil
     * sekali untuk seluruh payload, bukan satu query per baris.
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @return array<int,int>
     */
    private function usableProjectIds(array $lines): array
    {
        $projectIds = array_values(array_unique(array_filter(array_map(
            fn ($line) => isset($line['project_id']) ? (int) $line['project_id'] : null,
            $lines,
        ))));

        if ($projectIds === []) {
            return [];
        }

        return Project::query()
            ->whereIn('id', $projectIds)
            ->where('is_active', true)
            ->where('status', 'active')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Lapis pertama uniqueness. Unique index di DB sudah menutup celahnya sejak
     * fase 1, tapi pemeriksaan di sini dipertahankan supaya user dapat 422 dengan
     * penjelasan alih-alih DATABASE_ERROR mentah.
     *
     * Grain-nya ikut dimensi baru: akun + departemen + proyek + bulan.
     */
    private function validateLinesUnique(array $lines): void
    {
        $seen = [];
        foreach ($lines as $line) {
            $key = implode('|', [
                $line['account_id'] ?? '',
                $line['department_id'] ?? 'null',
                $line['project_id'] ?? 'null',
                $line['period_month'] ?? 'null',
            ]);

            if (isset($seen[$key])) {
                throw ApiException::make(
                    ApiErrorCode::VALIDATION_ERROR,
                    'Duplicate budget line: same account, department, project, and period.',
                    422
                );
            }

            $seen[$key] = true;
        }
    }
}
