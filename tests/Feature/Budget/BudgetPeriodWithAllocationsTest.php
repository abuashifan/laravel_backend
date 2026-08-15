<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Services\BudgetPeriodService;

/**
 * Form gabungan pagu+periode: `POST /budget-periods` sekarang bisa membuat
 * periode DAN pagu per departemennya sekaligus lewat `department_allocations`,
 * bukan periode dulu baru alokasi menyusul lewat panggilan terpisah
 * (`BudgetAllocationTest` yang menguji jalur lama itu).
 */
class BudgetPeriodWithAllocationsTest extends BudgetTestCase
{
    public function test_creating_a_period_with_department_allocations_creates_a_root_equal_to_the_sum(): void
    {
        $ctx = $this->setUpTenant();
        $marketing = $this->makeDepartment('MKT', 'Marketing');

        $res = $this->postJson('/api/budget-periods', [
            'fiscal_year' => 2026,
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'department_allocations' => [
                ['department_id' => $ctx['dept']->id, 'amount' => 60_000_000],
                ['department_id' => $marketing->id, 'amount' => 40_000_000],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $period = $res->json('data');

        $rows = $this->getJson("/api/budget-periods/{$period['id']}/allocations", $ctx['headers'])
            ->assertStatus(200)->json('data');

        $this->assertCount(3, $rows, 'Wajib ada 1 root + 2 baris departemen.');

        $root = collect($rows)->firstWhere('department_id', null);
        $this->assertNotNull($root, 'Root harus dibuat otomatis, bukan diinput manual.');
        $this->assertEqualsWithDelta(100_000_000, (float) $root['amount'], 0.01);

        $deptTotal = collect($rows)->whereNotNull('department_id')->sum(fn ($r) => (float) $r['amount']);
        $this->assertEqualsWithDelta(100_000_000, $deptTotal, 0.01);
    }

    public function test_creating_a_period_without_a_name_generates_one_from_the_fiscal_year(): void
    {
        $ctx = $this->setUpTenant();

        $res = $this->postJson('/api/budget-periods', [
            'fiscal_year' => 2027,
            'period_from' => '2027-01-01',
            'period_to' => '2027-12-31',
        ], $ctx['headers'])->assertStatus(201);

        $res->assertJsonPath('data.name', 'Pagu Anggaran 2027');
    }

    public function test_an_explicit_name_is_kept_as_is(): void
    {
        $ctx = $this->setUpTenant();

        $res = $this->postJson('/api/budget-periods', [
            'name' => 'Anggaran Khusus Ekspansi',
            'fiscal_year' => 2026,
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
        ], $ctx['headers'])->assertStatus(201);

        $res->assertJsonPath('data.name', 'Anggaran Khusus Ekspansi');
    }

    public function test_fiscal_year_id_is_stored_when_provided(): void
    {
        $ctx = $this->setUpTenant();

        $res = $this->postJson('/api/budget-periods', [
            'fiscal_year' => 2026,
            'fiscal_year_id' => 42,
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
        ], $ctx['headers'])->assertStatus(201);

        $res->assertJsonPath('data.fiscal_year_id', 42);
    }

    public function test_duplicate_department_in_the_same_request_is_rejected(): void
    {
        $ctx = $this->setUpTenant();

        $res = $this->postJson('/api/budget-periods', [
            'fiscal_year' => 2026,
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'department_allocations' => [
                ['department_id' => $ctx['dept']->id, 'amount' => 60_000_000],
                ['department_id' => $ctx['dept']->id, 'amount' => 10_000_000],
            ],
        ], $ctx['headers']);

        $res->assertStatus(422);
    }

    public function test_creating_a_period_without_any_allocations_still_works(): void
    {
        $ctx = $this->setUpTenant();

        $res = $this->postJson('/api/budget-periods', [
            'fiscal_year' => 2026,
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
        ], $ctx['headers'])->assertStatus(201);

        $rows = $this->getJson("/api/budget-periods/{$res->json('data.id')}/allocations", $ctx['headers'])
            ->assertStatus(200)->json('data');

        $this->assertSame([], $rows, 'Tanpa department_allocations, tidak boleh ada root yang dibuat sendirian.');
    }

    /**
     * Membuktikan `DB::transaction()` di `createWithAllocations()` benar-benar
     * membungkus periode DAN alokasinya jadi satu unit — kalau salah satu baris
     * alokasi gagal (di sini: dua department_id sama, melanggar unique index
     * `budget_allocations_period_department_unique`), periode itu sendiri TIDAK
     * BOLEH tersisa "nyangkut" tanpa pagu.
     *
     * Dipanggil langsung ke service (bukan lewat HTTP) supaya validasi
     * `distinct` di StoreBudgetPeriodRequest tidak mencegat lebih dulu — kita
     * memang ingin membuktikan lapisan transaksinya, bukan lapisan requestnya.
     */
    public function test_a_failed_allocation_rolls_back_the_whole_period(): void
    {
        $ctx = $this->setUpTenant();
        $countBefore = BudgetPeriod::query()->count();

        try {
            app(BudgetPeriodService::class)->createWithAllocations([
                'fiscal_year' => 2026,
                'period_from' => '2026-01-01',
                'period_to' => '2026-12-31',
                'department_allocations' => [
                    ['department_id' => $ctx['dept']->id, 'amount' => 60_000_000],
                    ['department_id' => $ctx['dept']->id, 'amount' => 10_000_000],
                ],
            ]);
            $this->fail('Seharusnya melempar exception karena department_id dobel.');
        } catch (\Throwable) {
            // Exception yang dilempar bukan fokus test ini — fokusnya adalah
            // efek sampingnya (atau tepatnya, ketiadaan efek samping) di bawah.
        }

        $this->assertSame($countBefore, BudgetPeriod::query()->count(), 'Periode tidak boleh tersimpan kalau alokasinya gagal.');
    }

    public function test_without_budgets_manage_permission_creation_is_forbidden(): void
    {
        $ctx = $this->setUpTenant('viewer');

        $this->postJson('/api/budget-periods', [
            'fiscal_year' => 2026,
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
        ], $ctx['headers'])->assertStatus(403);
    }
}
