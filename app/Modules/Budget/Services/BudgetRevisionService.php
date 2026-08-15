<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\BudgetLine;
use App\Modules\Budget\Models\BudgetSubmission;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\AuditLogService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Revisi anggaran yang **menjaga riwayat** (G2).
 *
 * Anggaran yang sudah disetujui bersifat immutable — `assertEditable()` menolak
 * edit langsung, dan aturan itu tidak dilonggarkan. Mengubahnya hanya bisa lewat
 * `revise()`, yang membuat submission BARU:
 *
 * 1. Salin header + seluruh baris, `version_no + 1`, status `draft`,
 *    `parent_submission_id` menunjuk versi lama, `revision_reason` wajib
 * 2. Versi lama → `superseded`, `is_active = false`
 * 3. Versi baru melewati rantai persetujuan yang sama
 *
 * Baris versi lama tidak pernah dihapus, jadi riwayat tetap terbaca dan tidak
 * ada transaksi yang perlu dimigrasi — actual selalu dihitung ulang dari ledger.
 *
 * **Revise ≠ Reject.** Reject adalah koreksi *sebelum* disetujui: kembali ke
 * draft di baris yang sama, `revision_number + 1`. Perilaku itu tidak disentuh.
 */
class BudgetRevisionService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        // Wajib — lihat catatan di BudgetSubmissionService.
        private readonly AuditLogService $auditLogService,
    ) {}

    public function revise(BudgetSubmission $submission, string $revisionReason): BudgetSubmission
    {
        if ($submission->status !== 'approved') {
            throw ApiException::make(
                ApiErrorCode::BUDGET_ALREADY_APPROVED,
                'Hanya anggaran yang sudah disetujui yang bisa direvisi. Anggaran berstatus draft cukup diedit langsung.',
                422,
            );
        }

        return DB::transaction(function () use ($submission, $revisionReason) {
            // Kunci seluruh grup versi supaya dua revisi bersamaan tidak
            // menghasilkan dua `is_active = true`.
            $this->lockVersionGroup($submission);

            $new = BudgetSubmission::query()->create([
                'company_id' => $submission->company_id,
                'budget_period_id' => $submission->budget_period_id,
                'parent_submission_id' => $submission->id,
                'department_id' => $submission->department_id,
                'status' => 'draft',
                'revision_number' => 1,
                'version_no' => $submission->version_no + 1,
                'is_active' => false,
                'revision_reason' => $revisionReason,
                'notes' => $submission->notes,
                'created_by' => auth()->id(),
            ]);

            $this->copyLines($submission, $new);

            $submission->update(['status' => 'superseded', 'is_active' => false]);

            $this->audit(AuditEvent::BUDGET_SUBMISSION_REVISED, $new, [
                'version_no' => $new->version_no,
                'parent_submission_id' => $submission->id,
                'revision_reason' => $revisionReason,
                'total_amount' => (string) $new->lines()->sum('amount'),
            ]);

            return $new->load('lines.account', 'lines.department', 'lines.project');
        });
    }

    /**
     * Seluruh rantai versi, terurut `version_no`, dengan total nominal per versi.
     * Sengaja tidak memuat barisnya — halaman riwayat hanya butuh ringkasan.
     *
     * @return array<int,array<string,mixed>>
     */
    public function versions(BudgetSubmission $submission): array
    {
        $rootId = $this->rootIdOf($submission);

        $chain = BudgetSubmission::query()
            ->forCompany($this->tenantContext->companyId())
            ->where(fn ($q) => $q->whereKey($rootId)->orWhere('parent_submission_id', $rootId))
            ->with('department')
            ->orderBy('version_no')
            ->get();

        // Rantai bisa lebih dari dua tingkat (v1 → v2 → v3), jadi turunkan terus
        // sampai tidak ada anak baru.
        $collected = $chain->keyBy('id');
        $frontier = $chain->pluck('id')->all();

        while ($frontier !== []) {
            $children = BudgetSubmission::query()
                ->forCompany($this->tenantContext->companyId())
                ->whereIn('parent_submission_id', $frontier)
                ->whereNotIn('id', $collected->keys()->all())
                ->with('department')
                ->get();

            if ($children->isEmpty()) {
                break;
            }

            foreach ($children as $child) {
                $collected->put($child->id, $child);
            }
            $frontier = $children->pluck('id')->all();
        }

        $totals = BudgetLine::query()
            ->whereIn('budget_submission_id', $collected->keys()->all())
            ->selectRaw('budget_submission_id, SUM(amount) as total_amount')
            ->groupBy('budget_submission_id')
            ->pluck('total_amount', 'budget_submission_id');

        return $collected
            ->sortBy('version_no')
            ->values()
            ->map(fn (BudgetSubmission $version) => [
                'id' => $version->id,
                'version_no' => $version->version_no,
                'parent_submission_id' => $version->parent_submission_id,
                'status' => $version->status,
                'is_active' => (bool) $version->is_active,
                'revision_number' => $version->revision_number,
                'revision_reason' => $version->revision_reason,
                'department_id' => $version->department_id,
                'department_name' => $version->department?->name,
                'created_by' => $version->created_by,
                'created_at' => $version->created_at?->toIso8601String(),
                'approved_at' => $version->approved_by_finance_at?->toIso8601String(),
                'total_amount' => number_format((float) ($totals[$version->id] ?? 0), 2, '.', ''),
            ])
            ->all();
    }

    /**
     * Salin dengan satu bulk insert, bukan loop `create()` per baris — pola yang
     * sama dipakai `updateLines()`. Anggaran bisa berisi ratusan baris.
     */
    private function copyLines(BudgetSubmission $from, BudgetSubmission $to): void
    {
        $now = now();

        $rows = $from->lines()->get()->map(fn (BudgetLine $line) => [
            'budget_submission_id' => $to->id,
            'account_id' => $line->account_id,
            'department_id' => $line->department_id,
            'project_id' => $line->project_id,
            'period_month' => $line->period_month,
            'direction' => $line->direction,
            'amount' => $line->amount,
            'notes' => $line->notes,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            BudgetLine::query()->insert($rows);
        }
    }

    private function lockVersionGroup(BudgetSubmission $submission): void
    {
        BudgetSubmission::query()
            ->where('budget_period_id', $submission->budget_period_id)
            ->when(
                $submission->department_id === null,
                fn ($q) => $q->whereNull('department_id'),
                fn ($q) => $q->where('department_id', $submission->department_id),
            )
            ->lockForUpdate()
            ->get();
    }

    private function rootIdOf(BudgetSubmission $submission): int
    {
        $current = $submission;

        // Batas iterasi menjaga rantai yang terlanjur melingkar tidak mengunci
        // proses; kedalaman versi realistis jauh di bawah ini.
        for ($i = 0; $i < 100 && $current->parent_submission_id !== null; $i++) {
            $parent = BudgetSubmission::query()->find($current->parent_submission_id);
            if ($parent === null) {
                break;
            }
            $current = $parent;
        }

        return (int) $current->id;
    }

    private function audit(string $event, BudgetSubmission $submission, array $meta = []): void
    {
        $this->auditLogService->logSuccess([
            'event' => $event,
            'module' => 'budget',
            'record_type' => 'budget_submission',
            'record_id' => (string) $submission->id,
            'user_id' => auth()->id(),
            'metadata' => $meta,
        ], tenant: true);
    }
}
