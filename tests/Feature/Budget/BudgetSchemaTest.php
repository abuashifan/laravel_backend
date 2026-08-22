<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Models\BudgetLine;
use App\Modules\Budget\Models\BudgetSubmission;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Project;
use App\Modules\MasterData\Services\DepartmentService;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 — fondasi data. Yang diuji di sini adalah bentuk datanya: dimensi ada
 * di baris, integritas referensial benar-benar menggigit, dan arah anggaran
 * diturunkan bukan diinput.
 */
class BudgetSchemaTest extends BudgetTestCase
{
    private function createPeriod(array $headers): array
    {
        return $this->postJson('/api/budget-periods', [
            'name' => 'Anggaran 2026',
            'fiscal_year' => 2026,
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
        ], $headers)->assertStatus(201)->json('data');
    }

    // ------------------------------------------------------------------ G1

    public function test_one_submission_holds_lines_with_different_cost_centers_and_projects(): void
    {
        $ctx = $this->setUpTenant();
        $period = $this->createPeriod($ctx['headers']);

        $marketing = $this->makeDepartment('MKT', 'Marketing');
        $classMeeting = $this->makeProject('PRJ-1', 'Class Meeting');
        $studyTour = $this->makeProject('PRJ-2', 'Study Tour');

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => $ctx['dept']->id,
        ], $ctx['headers'])->json('data');

        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [
                ['account_id' => $ctx['account']->id, 'department_id' => $ctx['dept']->id, 'project_id' => $classMeeting->id, 'amount' => 1000],
                ['account_id' => $ctx['account']->id, 'department_id' => $marketing->id, 'project_id' => $studyTour->id, 'amount' => 2000],
                ['account_id' => $ctx['account']->id, 'department_id' => $marketing->id, 'project_id' => null, 'period_month' => '2026-03', 'amount' => 3000],
            ],
        ], $ctx['headers'])->assertStatus(200);

        $lines = BudgetLine::query()->where('budget_submission_id', $submission['id'])->get();

        $this->assertCount(3, $lines);
        $this->assertEqualsCanonicalizing(
            [$ctx['dept']->id, $marketing->id, $marketing->id],
            $lines->pluck('department_id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$classMeeting->id, $studyTour->id, null],
            $lines->pluck('project_id')->all(),
        );
    }

    public function test_line_department_defaults_to_document_owner_but_explicit_null_is_respected(): void
    {
        $ctx = $this->setUpTenant();
        $period = $this->createPeriod($ctx['headers']);

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => $ctx['dept']->id,
        ], $ctx['headers'])->json('data');

        $other = $this->makeAccount('6100', 'Travel Expense');

        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [
                // Tanpa key department_id → warisi pemilik dokumen.
                ['account_id' => $ctx['account']->id, 'amount' => 1000],
                // Null eksplisit → lintas departemen.
                ['account_id' => $other->id, 'department_id' => null, 'amount' => 2000],
            ],
        ], $ctx['headers'])->assertStatus(200);

        $lines = BudgetLine::query()->where('budget_submission_id', $submission['id'])->get()->keyBy('account_id');

        $this->assertSame($ctx['dept']->id, $lines[$ctx['account']->id]->department_id);
        $this->assertNull($lines[$other->id]->department_id);
    }

    public function test_company_level_submission_can_be_created_without_department(): void
    {
        $ctx = $this->setUpTenant();
        $period = $this->createPeriod($ctx['headers']);

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => null,
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->assertNull($submission['department_id']);

        // Dua anggaran perusahaan untuk periode yang sama tetap ditolak — cek
        // duplikat harus memakai whereNull, bukan `= NULL` yang tak pernah cocok.
        $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => null,
        ], $ctx['headers'])->assertStatus(422);
    }

    // ------------------------------------------------------------------ G3

    public function test_unique_index_bites_even_when_dimensions_are_null(): void
    {
        $ctx = $this->setUpTenant();
        $period = $this->createPeriod($ctx['headers']);

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => null,
        ], $ctx['headers'])->json('data');

        BudgetLine::query()->create([
            'budget_submission_id' => $submission['id'],
            'account_id' => $ctx['account']->id,
            'department_id' => null,
            'project_id' => null,
            'period_month' => null,
            'direction' => 'expense',
            'amount' => 100,
        ]);

        // Unique biasa tidak akan menggigit di sini (NULL != NULL di SQL);
        // yang menahannya adalah unique index berbasis COALESCE.
        $this->expectException(QueryException::class);

        BudgetLine::query()->create([
            'budget_submission_id' => $submission['id'],
            'account_id' => $ctx['account']->id,
            'department_id' => null,
            'project_id' => null,
            'period_month' => null,
            'direction' => 'expense',
            'amount' => 200,
        ]);
    }

    public function test_service_rejects_duplicate_grain_before_the_database_does(): void
    {
        $ctx = $this->setUpTenant();
        $period = $this->createPeriod($ctx['headers']);
        $project = $this->makeProject('PRJ-1', 'Class Meeting');

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => $ctx['dept']->id,
        ], $ctx['headers'])->json('data');

        // Sama persis pada keempat dimensi → 422 yang menjelaskan, bukan
        // DATABASE_ERROR mentah dari unique index.
        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [
                ['account_id' => $ctx['account']->id, 'project_id' => $project->id, 'period_month' => '2026-01', 'amount' => 1000],
                ['account_id' => $ctx['account']->id, 'project_id' => $project->id, 'period_month' => '2026-01', 'amount' => 2000],
            ],
        ], $ctx['headers'])->assertStatus(422);

        // Beda departemen → grain berbeda, harus diterima.
        $marketing = $this->makeDepartment('MKT', 'Marketing');
        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [
                ['account_id' => $ctx['account']->id, 'department_id' => $ctx['dept']->id, 'project_id' => $project->id, 'period_month' => '2026-01', 'amount' => 1000],
                ['account_id' => $ctx['account']->id, 'department_id' => $marketing->id, 'project_id' => $project->id, 'period_month' => '2026-01', 'amount' => 2000],
            ],
        ], $ctx['headers'])->assertStatus(200);
    }

    public function test_foreign_keys_cascade_nullify_and_restrict(): void
    {
        $ctx = $this->setUpTenant();
        $period = $this->createPeriod($ctx['headers']);
        $project = $this->makeProject('PRJ-1', 'Class Meeting');

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => $ctx['dept']->id,
        ], $ctx['headers'])->json('data');

        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [['account_id' => $ctx['account']->id, 'project_id' => $project->id, 'amount' => 1000]],
        ], $ctx['headers'])->assertStatus(200);

        // Hapus proyek → dimensinya jadi NULL, barisnya tetap hidup.
        Project::query()->whereKey($project->id)->delete();
        $this->assertNull(BudgetLine::query()->where('budget_submission_id', $submission['id'])->value('project_id'));

        // Akun yang sedang dianggarkan tidak boleh terhapus.
        try {
            ChartOfAccount::query()->whereKey($ctx['account']->id)->delete();
            $this->fail('Menghapus akun yang dipakai baris anggaran seharusnya ditolak FK restrict.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        // Hapus submission → barisnya ikut terhapus.
        BudgetSubmission::query()->whereKey($submission['id'])->forceDelete();
        $this->assertSame(0, BudgetLine::query()->where('budget_submission_id', $submission['id'])->count());
    }

    // ------------------------------------------------------------ direction

    public function test_direction_is_derived_from_account_type(): void
    {
        $ctx = $this->setUpTenant();
        $period = $this->createPeriod($ctx['headers']);
        $revenue = $this->makeAccount('4000', 'Tuition Revenue', 'revenue');

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => $ctx['dept']->id,
        ], $ctx['headers'])->json('data');

        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [
                ['account_id' => $ctx['account']->id, 'amount' => 1000],
                ['account_id' => $revenue->id, 'amount' => 5000],
            ],
        ], $ctx['headers'])->assertStatus(200);

        $directions = BudgetLine::query()
            ->where('budget_submission_id', $submission['id'])
            ->pluck('direction', 'account_id');

        $this->assertSame('expense', $directions[$ctx['account']->id]);
        $this->assertSame('revenue', $directions[$revenue->id]);
    }

    public function test_balance_sheet_account_cannot_be_budgeted(): void
    {
        $ctx = $this->setUpTenant();
        $period = $this->createPeriod($ctx['headers']);
        $cash = $this->makeAccount('1000', 'Kas', 'asset');

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => $ctx['dept']->id,
        ], $ctx['headers'])->json('data');

        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [['account_id' => $cash->id, 'amount' => 1000]],
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'BUDGET_ACCOUNT_DIRECTION_MISMATCH');
    }

    // ----------------------------------------------------------------- G10

    public function test_department_hierarchy_accepts_nesting(): void
    {
        $this->setUpTenant();

        $root = $this->makeDepartment('DIR', 'Direktorat');
        $child = app(DepartmentService::class)->create([
            'code' => 'DIV', 'name' => 'Divisi', 'parent_id' => $root->id, 'is_active' => true,
        ]);

        $this->assertSame($root->id, $child->parent_id);
        $this->assertSame(1, Department::query()->where('parent_id', $root->id)->count());
        $this->assertTrue(Department::query()->roots()->where('id', $root->id)->exists());
    }

    public function test_department_cannot_become_its_own_ancestor(): void
    {
        $this->setUpTenant();
        $service = app(DepartmentService::class);

        $root = $this->makeDepartment('DIR', 'Direktorat');
        $child = $this->makeDepartment('DIV', 'Divisi', $root->id);

        // Induk = dirinya sendiri.
        try {
            $service->update($root, ['parent_id' => $root->id]);
            $this->fail('Departemen tidak boleh menjadi induk dirinya sendiri.');
        } catch (ApiException $e) {
            $this->assertSame('DEPARTMENT_HIERARCHY_CYCLE', $e->codeName);
        }

        // Induk = keturunannya sendiri.
        try {
            $service->update($root, ['parent_id' => $child->id]);
            $this->fail('Departemen tidak boleh dipindahkan ke bawah keturunannya.');
        } catch (ApiException $e) {
            $this->assertSame('DEPARTMENT_HIERARCHY_CYCLE', $e->codeName);
        }
    }

    public function test_department_hierarchy_is_capped_at_five_levels(): void
    {
        $this->setUpTenant();
        $service = app(DepartmentService::class);

        $parentId = null;
        for ($level = 1; $level <= DepartmentService::MAX_HIERARCHY_DEPTH; $level++) {
            $parentId = $service->create([
                'code' => 'LV'.$level, 'name' => 'Level '.$level, 'parent_id' => $parentId, 'is_active' => true,
            ])->id;
        }

        try {
            $service->create(['code' => 'LV6', 'name' => 'Level 6', 'parent_id' => $parentId, 'is_active' => true]);
            $this->fail('Level ke-6 seharusnya ditolak.');
        } catch (ApiException $e) {
            $this->assertSame('DEPARTMENT_HIERARCHY_TOO_DEEP', $e->codeName);
        }
    }

    public function test_moving_a_subtree_counts_its_deepest_descendant(): void
    {
        $this->setUpTenant();
        $service = app(DepartmentService::class);

        // Rantai kedalaman 3 yang berdiri sendiri.
        $a = $this->makeDepartment('A', 'A');
        $b = $this->makeDepartment('B', 'B', $a->id);
        $this->makeDepartment('C', 'C', $b->id);

        // Rantai lain sedalam 3 level.
        $x = $this->makeDepartment('X', 'X');
        $y = $this->makeDepartment('Y', 'Y', $x->id);
        $z = $this->makeDepartment('Z', 'Z', $y->id);

        // Memindahkan A (tinggi 3) ke bawah Z (kedalaman 3) menghasilkan level 6.
        try {
            $service->update($a, ['parent_id' => $z->id]);
            $this->fail('Pemindahan subtree yang membuat level 6 seharusnya ditolak.');
        } catch (ApiException $e) {
            $this->assertSame('DEPARTMENT_HIERARCHY_TOO_DEEP', $e->codeName);
        }

        // Ke bawah Y (kedalaman 2) menghasilkan level 5 — masih boleh.
        $service->update($a, ['parent_id' => $y->id]);
        $this->assertSame($y->id, $a->refresh()->parent_id);
    }

    // ------------------------------------------------------- guard migration

    public function test_budget_lines_migration_refuses_to_drop_existing_data(): void
    {
        $ctx = $this->setUpTenant();
        $period = $this->createPeriod($ctx['headers']);

        $submission = $this->postJson("/api/budget-periods/{$period['id']}/submissions", [
            'department_id' => $ctx['dept']->id,
        ], $ctx['headers'])->json('data');

        $this->putJson("/api/budget-submissions/{$submission['id']}/lines", [
            'lines' => [['account_id' => $ctx['account']->id, 'amount' => 1000]],
        ], $ctx['headers'])->assertStatus(200);

        $migration = require database_path('migrations/tenant/2026_08_14_000004_recreate_budget_lines_table_with_dimensions.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/berisi 1 baris/');

        $migration->up();
    }

    public function test_budget_lines_migration_runs_on_empty_table(): void
    {
        $this->setUpTenant();

        $migration = require database_path('migrations/tenant/2026_08_14_000004_recreate_budget_lines_table_with_dimensions.php');
        $migration->up();

        $this->assertSame(0, DB::connection('tenant')->table('budget_lines')->count());
    }
}
