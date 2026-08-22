<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\BudgetAllocation;
use App\Modules\Budget\Models\BudgetPeriod;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\AuditLogService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pagu top-down — Gap A. Dua tingkat SAJA untuk saat ini: root (pagu
 * perusahaan) dan anaknya (pagu departemen). Proyek tidak dapat pagu sendiri
 * (Gap F) — ia tetap dimensi pelaporan di `budget_lines`, bukan penerima
 * alokasi di sini.
 *
 * Tabel ini terpisah dari `budget_submissions`/`budget_lines` dengan sengaja:
 * pagu adalah batas yang ditetapkan SEBELUM usulan ada (top-down), sedangkan
 * submission adalah usulan yang MENJADI plafon begitu disetujui (bottom-up).
 * Keduanya dibandingkan di UI, tidak digabung jadi satu tabel.
 */
class BudgetAllocationService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        // Wajib, bukan `?AuditLogService = null` — lihat catatan yang sama di
        // BudgetSubmissionService. Parameter ber-default tidak pernah diisi
        // container, jadi audit trail di pola itu diam-diam tidak pernah menulis.
        private readonly AuditLogService $auditLogService,
    ) {}

    /** @return Collection<int,BudgetAllocation> */
    public function list(BudgetPeriod $period): Collection
    {
        return BudgetAllocation::query()
            ->forCompany($this->tenantContext->companyId())
            ->where('budget_period_id', $period->id)
            ->with('department:id,code,name')
            // Root dulu, baru anak-anaknya — urutan yang wajar dibaca sebagai pohon.
            ->orderByRaw('department_id IS NOT NULL')
            ->orderBy('department_id')
            ->get();
    }

    public function find(int $id): BudgetAllocation
    {
        return BudgetAllocation::query()
            ->forCompany($this->tenantContext->companyId())
            ->findOrFail($id);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(BudgetPeriod $period, array $data): BudgetAllocation
    {
        $companyId = $this->tenantContext->companyId();
        $departmentId = isset($data['department_id']) ? (int) $data['department_id'] : null;
        $parentId = isset($data['parent_allocation_id']) ? (int) $data['parent_allocation_id'] : null;
        $amount = (float) $data['amount'];

        $parent = $this->assertValidParent($period, $departmentId, $parentId);

        if ($parent !== null) {
            $this->assertWithinParent($parent, $amount, excludeAllocationId: null);
        }

        $allocation = BudgetAllocation::query()->create([
            'company_id' => $companyId,
            'budget_period_id' => $period->id,
            'department_id' => $departmentId,
            'parent_allocation_id' => $parent?->id,
            'amount' => $amount,
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
        ]);

        $this->audit(AuditEvent::BUDGET_ALLOCATION_CREATED, $allocation);

        return $allocation->load('department:id,code,name');
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(BudgetAllocation $allocation, array $data): BudgetAllocation
    {
        $amount = array_key_exists('amount', $data) ? (float) $data['amount'] : (float) $allocation->amount;

        if ($allocation->isRoot()) {
            // Menurunkan pagu perusahaan tidak boleh membuat total pagu
            // departemen yang sudah ada melebihinya — itu akan meninggalkan
            // alokasi yang sudah disetujui secara diam-diam tidak konsisten.
            $this->assertChildrenWithinAmount($allocation, $amount);
        } else {
            $parent = $allocation->parent_allocation_id !== null
                ? BudgetAllocation::query()->forCompany($allocation->company_id)->find($allocation->parent_allocation_id)
                : null;

            if ($parent !== null) {
                $this->assertWithinParent($parent, $amount, excludeAllocationId: $allocation->id);
            }
        }

        $allocation->update([
            'amount' => $amount,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $allocation->notes,
        ]);

        $this->audit(AuditEvent::BUDGET_ALLOCATION_UPDATED, $allocation);

        return $allocation->refresh()->load('department:id,code,name');
    }

    /**
     * Menentukan dan memvalidasi induk pagu ini.
     *
     * - `department_id` NULL → ini pagu root. Tidak boleh punya induk, dan
     *   tidak boleh ada root lain untuk periode yang sama (unique index yang
     *   menegakkan itu; di sini baru pengecekan ramah supaya pesannya jelas).
     * - `department_id` diisi → WAJIB punya induk, dan induknya WAJIB root.
     *   Dibatasi sengaja ke dua tingkat (Gap F) — proyek tidak dapat pagu sendiri
     *   sehingga tidak ada alasan pagu departemen punya anak pagu lagi.
     */
    private function assertValidParent(BudgetPeriod $period, ?int $departmentId, ?int $parentId): ?BudgetAllocation
    {
        if ($departmentId === null) {
            if ($parentId !== null) {
                throw ApiException::make(
                    ApiErrorCode::VALIDATION_ERROR,
                    'Pagu tingkat perusahaan tidak boleh punya induk.',
                    422,
                    ['parent_allocation_id' => ['Kosongkan untuk pagu tingkat perusahaan.']],
                );
            }

            return null;
        }

        if ($parentId === null) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                'Pagu departemen wajib menunjuk pagu induknya (pagu perusahaan).',
                422,
                ['parent_allocation_id' => ['Wajib diisi untuk pagu departemen.']],
            );
        }

        $parent = BudgetAllocation::query()
            ->forCompany($this->tenantContext->companyId())
            ->where('budget_period_id', $period->id)
            ->find($parentId);

        if ($parent === null) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                'Pagu induk tidak ditemukan pada periode ini.',
                422,
                ['parent_allocation_id' => ['Pagu induk tidak ditemukan pada periode ini.']],
            );
        }

        if (! $parent->isRoot()) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                'Pagu departemen hanya boleh menunjuk pagu tingkat perusahaan, bukan pagu departemen lain.',
                422,
                ['parent_allocation_id' => ['Hanya pagu tingkat perusahaan yang boleh jadi induk.']],
            );
        }

        return $parent;
    }

    /**
     * SUM(amount) seluruh pagu yang menunjuk `$parent` — termasuk pagu yang
     * sedang dibuat/diubah — tidak boleh melebihi `$parent->amount`.
     */
    private function assertWithinParent(BudgetAllocation $parent, float $amount, ?int $excludeAllocationId): void
    {
        $siblingTotal = (float) BudgetAllocation::query()
            ->forCompany($parent->company_id)
            ->where('parent_allocation_id', $parent->id)
            ->when($excludeAllocationId !== null, fn ($q) => $q->whereKeyNot($excludeAllocationId))
            ->sum('amount');

        $newTotal = $siblingTotal + $amount;

        if ($newTotal > (float) $parent->amount + 0.0001) {
            throw ApiException::make(
                ApiErrorCode::BUDGET_ALLOCATION_EXCEEDS_PARENT,
                sprintf(
                    'Total pagu departemen (%s) akan melebihi pagu perusahaan (%s). Sisa yang tersedia: %s.',
                    number_format($newTotal, 2, ',', '.'),
                    number_format((float) $parent->amount, 2, ',', '.'),
                    number_format(max(0, (float) $parent->amount - $siblingTotal), 2, ',', '.'),
                ),
                422,
                ['amount' => ['Melebihi sisa pagu induk.']],
            );
        }
    }

    /** Kebalikan `assertWithinParent()` — dipanggil saat pagu INDUK diturunkan. */
    private function assertChildrenWithinAmount(BudgetAllocation $root, float $newAmount): void
    {
        $childrenTotal = (float) BudgetAllocation::query()
            ->forCompany($root->company_id)
            ->where('parent_allocation_id', $root->id)
            ->sum('amount');

        if ($childrenTotal > $newAmount + 0.0001) {
            throw ApiException::make(
                ApiErrorCode::BUDGET_ALLOCATION_EXCEEDS_PARENT,
                sprintf(
                    'Pagu perusahaan tidak bisa diturunkan ke %s — total pagu departemen yang sudah dialokasikan adalah %s.',
                    number_format($newAmount, 2, ',', '.'),
                    number_format($childrenTotal, 2, ',', '.'),
                ),
                422,
                ['amount' => ['Turunkan atau hapus pagu departemen terlebih dahulu.']],
            );
        }
    }

    private function audit(string $event, BudgetAllocation $allocation, array $meta = []): void
    {
        $this->auditLogService->logSuccess([
            'event' => $event,
            'module' => 'budget',
            'record_type' => 'budget_allocation',
            'record_id' => (string) $allocation->id,
            'user_id' => auth()->id(),
            'metadata' => $meta + [
                'budget_period_id' => $allocation->budget_period_id,
                'department_id' => $allocation->department_id,
                'amount' => (string) $allocation->amount,
            ],
        ], tenant: true);
    }
}
