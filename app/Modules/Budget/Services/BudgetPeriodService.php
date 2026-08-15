<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\BudgetPeriod;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Api\AppliesListQuery;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\AuditLogService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Tenant\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BudgetPeriodService
{
    use AppliesListQuery;

    protected array $listSearchable = ['name', 'fiscal_year'];

    protected array $listSearchableRelations = [];

    protected string $listDateColumn = 'period_from';

    protected string $listStatusColumn = 'status';

    protected array $listDefaultSort = ['fiscal_year' => 'desc', 'period_from' => 'desc'];

    protected array $listSortable = ['name', 'fiscal_year', 'period_from', 'period_to', 'status', 'created_at'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        // Wajib — lihat catatan di BudgetSubmissionService: varian nullable
        // dengan nilai default tidak pernah diisi container.
        private readonly AuditLogService $auditLogService,
        private readonly BudgetAllocationService $allocationService,
    ) {}

    /**
     * Search/filter/sort/paginate dikerjakan di SQL lewat `AppliesListQuery`,
     * bukan di klien. Sebelumnya method ini selalu mengembalikan SELURUH koleksi
     * dan `BudgetPeriodListPage` menyaring serta memotong halaman di browser —
     * artinya sort "tahun terbesar" hanya berlaku untuk baris yang kebetulan
     * sudah terunduh, dan tiap pagu baru menambah beban transfer.
     *
     * Kontrak `listResponse()` tetap dihormati: tanpa `page`/`per_page` di
     * request, hasilnya Collection utuh — dipakai `BudgetPeriodSelect` dan
     * beberapa halaman analisis yang memang butuh semua periode untuk dropdown.
     *
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator|Collection<int,BudgetPeriod>
     */
    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $query = BudgetPeriod::query()
            ->forCompany($this->tenantContext->companyId())
            ->withCount('submissions');

        return $this->applyListQuery($query, $filters);
    }

    public function create(array $data): BudgetPeriod
    {
        $companyId = $this->tenantContext->companyId();

        $this->assertNoOverlap(
            $companyId,
            (int) $data['fiscal_year'],
            (string) $data['period_from'],
            (string) $data['period_to'],
        );

        $period = BudgetPeriod::query()->create([
            'company_id' => $companyId,
            'name' => trim((string) ($data['name'] ?? '')) !== ''
                ? $data['name']
                : "Pagu Anggaran {$data['fiscal_year']}",
            'fiscal_year' => $data['fiscal_year'],
            'fiscal_year_id' => $data['fiscal_year_id'] ?? null,
            'period_from' => $data['period_from'],
            'period_to' => $data['period_to'],
            'status' => 'open',
            'beginning_cash_override' => $data['beginning_cash_override'] ?? null,
            'created_by' => auth()->id(),
        ]);

        $this->audit(AuditEvent::BUDGET_PERIOD_CREATED, $period);

        return $period;
    }

    /**
     * Form gabungan pagu+periode: satu periode DAN pagu per departemennya
     * dibuat dalam satu transaksi, bukan periode dulu lalu alokasi menyusul
     * lewat panggilan terpisah — supaya tidak ada periode yang "nyangkut"
     * tanpa pagu kalau salah satu baris departemen gagal validasi.
     *
     * Pagu tingkat perusahaan (root) TIDAK diinput manual — nilainya SELALU
     * SUM dari baris departemen yang dikirim, supaya validasi
     * `BudgetAllocationService::assertWithinParent()` (anak tidak boleh
     * melebihi induk) otomatis terpenuhi by construction, bukan sesuatu yang
     * bisa gagal karena user salah menghitung total lebih dulu.
     *
     * @param  array<string,mixed>  $data
     */
    public function createWithAllocations(array $data): BudgetPeriod
    {
        // `DB::transaction()` biasa membungkus koneksi DEFAULT, bukan
        // 'tenant' — dan BudgetPeriod/BudgetAllocation keduanya
        // `protected $connection = 'tenant'`. Tanpa connection('tenant')
        // eksplisit di sini, exception di tengah loop alokasi TIDAK
        // membatalkan periode yang sudah terlanjur dibuat (dibuktikan lewat
        // BudgetPeriodWithAllocationsTest::test_a_failed_allocation_rolls_back_the_whole_period).
        return DB::connection('tenant')->transaction(function () use ($data) {
            $period = $this->create($data);

            $rows = $data['department_allocations'] ?? [];
            if ($rows === []) {
                return $period;
            }

            $total = array_sum(array_map(static fn ($row) => (float) $row['amount'], $rows));

            $root = $this->allocationService->create($period, [
                'department_id' => null,
                'parent_allocation_id' => null,
                'amount' => $total,
            ]);

            foreach ($rows as $row) {
                $this->allocationService->create($period, [
                    'department_id' => $row['department_id'],
                    'parent_allocation_id' => $root->id,
                    'amount' => $row['amount'],
                    'notes' => $row['notes'] ?? null,
                ]);
            }

            return $period;
        });
    }

    public function find(int $id): BudgetPeriod
    {
        $companyId = $this->tenantContext->companyId();

        return BudgetPeriod::query()
            ->forCompany($companyId)
            ->findOrFail($id);
    }

    public function update(BudgetPeriod $period, array $data): BudgetPeriod
    {
        if ($period->status === 'closed') {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Cannot update a closed budget period.', 422);
        }

        $this->assertNoOverlap(
            (int) $period->company_id,
            (int) ($data['fiscal_year'] ?? $period->fiscal_year),
            (string) ($data['period_from'] ?? $period->period_from),
            (string) ($data['period_to'] ?? $period->period_to),
            ignoreId: (int) $period->id,
        );

        $period->update([
            'name' => $data['name'] ?? $period->name,
            'fiscal_year' => $data['fiscal_year'] ?? $period->fiscal_year,
            'fiscal_year_id' => $data['fiscal_year_id'] ?? $period->fiscal_year_id,
            'period_from' => $data['period_from'] ?? $period->period_from,
            'period_to' => $data['period_to'] ?? $period->period_to,
            'beginning_cash_override' => array_key_exists('beginning_cash_override', $data)
                ? $data['beginning_cash_override']
                : $period->beginning_cash_override,
        ]);

        $this->audit(AuditEvent::BUDGET_PERIOD_UPDATED, $period);

        return $period->refresh();
    }

    public function close(BudgetPeriod $period): BudgetPeriod
    {
        if ($period->status === 'closed') {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Budget period is already closed.', 422);
        }

        $period->update(['status' => 'closed']);

        $this->audit(AuditEvent::BUDGET_PERIOD_CLOSED, $period);

        return $period->refresh();
    }

    /**
     * Dua periode anggaran yang masih `open` pada fiscal year yang sama tidak
     * boleh beririsan tanggal — kalau beririsan, satu transaksi bisa dibandingkan
     * dengan dua anggaran sekaligus dan angkanya jadi tidak bisa dipertanggungjawabkan.
     *
     * Periode `closed` tidak ikut diperiksa: ia bagian dari riwayat, dan
     * membuat periode baru tidak boleh terhalang olehnya.
     */
    private function assertNoOverlap(int $companyId, int $fiscalYear, string $from, string $to, ?int $ignoreId = null): void
    {
        $overlapping = BudgetPeriod::query()
            ->forCompany($companyId)
            ->where('fiscal_year', $fiscalYear)
            ->where('status', 'open')
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            // Beririsan bila mulai sebelum periode lain berakhir DAN berakhir
            // setelah periode lain dimulai.
            ->where('period_from', '<=', $to)
            ->where('period_to', '>=', $from)
            ->first();

        if ($overlapping !== null) {
            throw ApiException::make(
                ApiErrorCode::BUDGET_PERIOD_OVERLAP,
                "Periode anggaran beririsan dengan \"{$overlapping->name}\" yang masih terbuka.",
                422,
                ['period_from' => ['Periode beririsan dengan periode anggaran lain yang masih terbuka.']],
            );
        }
    }

    private function audit(string $event, BudgetPeriod $period, array $meta = []): void
    {
        $this->auditLogService->logSuccess([
            'event' => $event,
            'module' => 'budget',
            'record_type' => 'budget_period',
            'record_id' => (string) $period->id,
            'record_number' => $period->name,
            'user_id' => auth()->id(),
            'metadata' => $meta + [
                'fiscal_year' => $period->fiscal_year,
                'status' => $period->status,
            ],
        ], tenant: true);
    }
}
