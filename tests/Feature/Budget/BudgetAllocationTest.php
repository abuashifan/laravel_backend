<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Models\BudgetAllocation;

/**
 * Gap A — pagu top-down. Tabel ini terpisah dari `budget_submissions`/
 * `budget_lines` (yang tetap bottom-up); di sini hanya dua tingkat yang
 * ditegakkan: root (pagu perusahaan) dan anaknya (pagu departemen). Proyek
 * tidak dapat pagu sendiri (Gap F) — test di bawah membuktikan servicenya
 * menolak percobaan membuat tingkat ketiga.
 */
class BudgetAllocationTest extends BudgetAnalysisTestCase
{
    private function createRoot(float $amount = 100_000_000): array
    {
        $res = $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => null, 'amount' => $amount],
            $this->headers,
        )->assertStatus(201)->json('data');

        return $res;
    }

    public function test_it_creates_a_company_level_root_allocation(): void
    {
        $this->bootScenario();

        $res = $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => null, 'amount' => 100_000_000, 'notes' => 'Pagu 2026'],
            $this->headers,
        )->assertStatus(201);

        $res->assertJsonPath('data.department_id', null)
            ->assertJsonPath('data.parent_allocation_id', null);
        $this->assertEqualsWithDelta(100_000_000, (float) $res->json('data.amount'), 0.01);
    }

    public function test_it_creates_a_department_allocation_under_the_root(): void
    {
        $this->bootScenario();
        $root = $this->createRoot(100_000_000);

        $res = $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => $this->dept->id, 'parent_allocation_id' => $root['id'], 'amount' => 60_000_000],
            $this->headers,
        )->assertStatus(201);

        $res->assertJsonPath('data.department_id', $this->dept->id)
            ->assertJsonPath('data.parent_allocation_id', $root['id']);
    }

    public function test_department_allocations_exceeding_the_root_are_rejected(): void
    {
        $this->bootScenario();
        $root = $this->createRoot(100_000_000);
        $marketing = $this->makeDepartment('MKT', 'Marketing');

        $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => $this->dept->id, 'parent_allocation_id' => $root['id'], 'amount' => 70_000_000],
            $this->headers,
        )->assertStatus(201);

        // 70jt + 40jt = 110jt > pagu perusahaan 100jt.
        $res = $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => $marketing->id, 'parent_allocation_id' => $root['id'], 'amount' => 40_000_000],
            $this->headers,
        )->assertStatus(422);

        $res->assertJsonPath('code', 'BUDGET_ALLOCATION_EXCEEDS_PARENT');
    }

    public function test_department_allocations_exactly_at_the_root_amount_are_allowed(): void
    {
        $this->bootScenario();
        $root = $this->createRoot(100_000_000);
        $marketing = $this->makeDepartment('MKT', 'Marketing');

        $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => $this->dept->id, 'parent_allocation_id' => $root['id'], 'amount' => 60_000_000],
            $this->headers,
        )->assertStatus(201);

        // Tepat pas di batas — 60jt + 40jt = 100jt, tidak boleh ditolak.
        $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => $marketing->id, 'parent_allocation_id' => $root['id'], 'amount' => 40_000_000],
            $this->headers,
        )->assertStatus(201);
    }

    public function test_a_second_root_for_the_same_period_is_rejected(): void
    {
        $this->bootScenario();
        $this->createRoot(100_000_000);

        $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => null, 'amount' => 50_000_000],
            $this->headers,
        )->assertStatus(422);
    }

    public function test_a_second_allocation_for_the_same_department_in_the_same_period_is_rejected(): void
    {
        $this->bootScenario();
        $root = $this->createRoot(100_000_000);

        $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => $this->dept->id, 'parent_allocation_id' => $root['id'], 'amount' => 30_000_000],
            $this->headers,
        )->assertStatus(201);

        $res = $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => $this->dept->id, 'parent_allocation_id' => $root['id'], 'amount' => 10_000_000],
            $this->headers,
        );

        $res->assertStatus(422);
    }

    public function test_a_department_allocation_without_a_parent_is_rejected(): void
    {
        $this->bootScenario();
        $this->createRoot(100_000_000);

        $res = $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => $this->dept->id, 'amount' => 30_000_000],
            $this->headers,
        );

        $res->assertStatus(422);
    }

    public function test_a_root_allocation_with_a_parent_is_rejected(): void
    {
        $this->bootScenario();
        $root = $this->createRoot(100_000_000);

        $res = $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => null, 'parent_allocation_id' => $root['id'], 'amount' => 10_000_000],
            $this->headers,
        );

        $res->assertStatus(422);
    }

    /**
     * Gap F: proyek tidak dapat pagu sendiri. Mencoba membuat pagu "tingkat
     * ketiga" (menunjuk pagu departemen sebagai induk, bukan pagu perusahaan)
     * harus ditolak — bukan cuma tidak didukung UI, tapi ditolak service.
     */
    public function test_an_allocation_cannot_point_to_a_department_allocation_as_its_parent(): void
    {
        $this->bootScenario();
        $root = $this->createRoot(100_000_000);
        $marketing = $this->makeDepartment('MKT', 'Marketing');

        $deptAllocation = $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => $this->dept->id, 'parent_allocation_id' => $root['id'], 'amount' => 60_000_000],
            $this->headers,
        )->assertStatus(201)->json('data');

        $res = $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => $marketing->id, 'parent_allocation_id' => $deptAllocation['id'], 'amount' => 10_000_000],
            $this->headers,
        );

        $res->assertStatus(422);
    }

    public function test_lowering_the_root_below_its_existing_children_is_rejected(): void
    {
        $this->bootScenario();
        $root = $this->createRoot(100_000_000);

        $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => $this->dept->id, 'parent_allocation_id' => $root['id'], 'amount' => 80_000_000],
            $this->headers,
        )->assertStatus(201);

        // Anak sudah 80jt; menurunkan induk ke 70jt tidak boleh lolos diam-diam.
        $res = $this->putJson(
            "/api/budget-allocations/{$root['id']}",
            ['amount' => 70_000_000],
            $this->headers,
        );

        $res->assertStatus(422)->assertJsonPath('code', 'BUDGET_ALLOCATION_EXCEEDS_PARENT');
    }

    public function test_raising_a_department_allocation_beyond_the_root_is_rejected_on_update(): void
    {
        $this->bootScenario();
        $root = $this->createRoot(100_000_000);
        $deptAllocation = $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => $this->dept->id, 'parent_allocation_id' => $root['id'], 'amount' => 60_000_000],
            $this->headers,
        )->assertStatus(201)->json('data');

        $res = $this->putJson(
            "/api/budget-allocations/{$deptAllocation['id']}",
            ['amount' => 150_000_000],
            $this->headers,
        );

        $res->assertStatus(422)->assertJsonPath('code', 'BUDGET_ALLOCATION_EXCEEDS_PARENT');
    }

    public function test_updating_a_department_allocation_within_its_own_previous_amount_is_allowed(): void
    {
        $this->bootScenario();
        $root = $this->createRoot(100_000_000);
        $deptAllocation = $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => $this->dept->id, 'parent_allocation_id' => $root['id'], 'amount' => 60_000_000],
            $this->headers,
        )->assertStatus(201)->json('data');

        // Menaikkan dari 60jt ke 90jt masih di bawah pagu perusahaan 100jt —
        // wajib lolos. Ini membuktikan exclude-self bekerja: tanpa itu baris
        // ini akan dihitung dobel terhadap dirinya sendiri dan selalu ditolak.
        $res = $this->putJson(
            "/api/budget-allocations/{$deptAllocation['id']}",
            ['amount' => 90_000_000],
            $this->headers,
        );

        $res->assertStatus(200);
        $this->assertEqualsWithDelta(90_000_000, (float) $res->json('data.amount'), 0.01);
    }

    public function test_list_returns_root_before_departments(): void
    {
        $this->bootScenario();
        $root = $this->createRoot(100_000_000);
        $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => $this->dept->id, 'parent_allocation_id' => $root['id'], 'amount' => 60_000_000],
            $this->headers,
        )->assertStatus(201);

        $rows = $this->getJson("/api/budget-periods/{$this->period->id}/allocations", $this->headers)
            ->assertStatus(200)->json('data');

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]['department_id']);
        $this->assertSame($this->dept->id, $rows[1]['department_id']);
    }

    public function test_allocations_of_another_company_are_not_listed(): void
    {
        $this->bootScenario();
        $root = $this->createRoot(100_000_000);
        $marketing = $this->makeDepartment('MKT', 'Marketing');

        // Baris "company lain" di tenant DB yang sama — department berbeda supaya
        // tidak bentrok dengan unique index (period, department), tapi
        // `company_id`-nya sengaja tidak cocok dengan tenant aktif. forCompany()
        // yang menjaga batasnya, sama seperti test setara di
        // BudgetSubmissionListTest.
        BudgetAllocation::query()->create([
            'company_id' => (int) $this->period->company_id + 999,
            'budget_period_id' => $this->period->id,
            'department_id' => $marketing->id,
            'parent_allocation_id' => $root['id'],
            'amount' => 50_000_000,
            'created_by' => $this->submission->created_by,
        ]);

        $rows = $this->getJson("/api/budget-periods/{$this->period->id}/allocations", $this->headers)
            ->assertStatus(200)->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($root['id'], $rows[0]['id']);
    }

    public function test_negative_amount_is_rejected(): void
    {
        $this->bootScenario();

        $this->postJson(
            "/api/budget-periods/{$this->period->id}/allocations",
            ['department_id' => null, 'amount' => -1],
            $this->headers,
        )->assertStatus(422);
    }

    public function test_without_budgets_manage_permission_creation_is_forbidden(): void
    {
        // Role 'viewer' tidak memegang budgets.manage (bahkan budgets.view) di
        // katalog permission bawaan — lihat config/permissions.php. Middleware
        // permission menolak sebelum controller sempat mencari periodenya, jadi
        // id periode di sini tidak perlu benar-benar ada di tenant ini.
        $ctx = $this->setUpTenant('viewer');

        $this->postJson(
            '/api/budget-periods/999999/allocations',
            ['department_id' => null, 'amount' => 100_000_000],
            $ctx['headers'],
        )->assertStatus(403);
    }
}
