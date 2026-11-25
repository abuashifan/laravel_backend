<?php

namespace Tests\Feature\Workspace;

use App\Modules\Workspace\Support\BudgetPermission;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Subscription;
use App\Shared\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Journal\JournalTestCase;

class WorkspaceBudgetTest extends JournalTestCase
{
    private const API = '/api/workspace/budget';

    private array $headers;

    public function test_approval_consumes_pagu_per_pos_and_preserves_history_across_annual_revisions(): void
    {
        $tenant = $this->setUpWorkspace();
        $period = $this->createPeriod('2026-07-01');
        $unit = $this->createUnit('A');
        $otherUnit = $this->createUnit('B');
        $annual = $this->createBudget($this->annual($period, $unit, '100.40'));
        $this->postJson(self::API.'/requests', $this->annual($period, $unit, '100.40'), $this->headers)->assertConflict();
        $this->postJson(self::API.'/requests', $this->activity($period, $unit, 1, '60'), $this->headers)->assertUnprocessable();

        $this->action($annual, 'submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->action($annual, 'approve')->assertOk()->assertJsonPath('data.approved_annual_id', $annual);
        $itemsPath = self::API."/items?kind=activity&period_id=$period&unit_id=$unit";
        $item = $this->getJson($itemsPath, $this->headers)->assertOk()->json('data.0.id');
        $this->postJson(self::API.'/requests', $this->activity($period, $otherUnit, $item, '60'), $this->headers)->assertUnprocessable();
        $firstPayload = $this->activity($period, $unit, $item, '37.25');
        $firstPayload['lines'][] = ['item_id' => $item, 'description' => 'Tol', 'amount' => '28.15'];
        $first = $this->createBudget($firstPayload);
        $second = $this->createBudget($this->activity($period, $unit, $item, '35.01'));

        $this->action($first, 'submit')->assertOk();
        $this->action($second, 'submit')->assertOk();
        $this->getJson($itemsPath, $this->headers)->assertOk()->assertJsonPath('data.0.committed_minor', 0);
        $this->action($first, 'approve')->assertOk()->assertJsonPath('data.annual_version_id', $annual);
        $this->action($first, 'approve')->assertConflict();
        $this->action($second, 'approve')->assertUnprocessable()->assertJsonPath('code', 'WORKSPACE_BUDGET_LIMIT_EXCEEDED');
        $this->getJson($itemsPath, $this->headers)->assertOk()->assertJsonPath('data.0.committed_minor', 6540);

        $this->action($second, 'reject')->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->action($second, 'reject', 'Pagu tersisa Rp35.')->assertOk();
        $nextActivity = $this->action($second, 'revise')->assertCreated()->assertJsonPath('data.version', 2)->json('data.id');
        $this->assertNotEquals($nextActivity, $second);

        $revision = $this->action($annual, 'revise')->assertCreated()->assertJsonPath('data.previous_id', $annual)->json('data.id');
        $payload = $this->annual($period, $unit, '65.39');
        unset($payload['period_id'], $payload['unit_id']);
        $this->putJson(self::API."/requests/$revision", $payload, $this->headers)->assertOk();
        $this->action($revision, 'submit')->assertOk();
        $this->action($revision, 'approve')->assertUnprocessable()->assertJsonPath('code', 'WORKSPACE_BUDGET_COMMITMENT_EXISTS');
        $this->action($revision, 'reject', 'Di bawah komitmen')->assertOk();
        $revision = $this->action($revision, 'revise')->assertCreated()->json('data.id');
        $payload['lines'][0]['amount'] = '95.40';
        $this->putJson(self::API."/requests/$revision", $payload, $this->headers)->assertOk();
        $this->action($revision, 'submit')->assertOk();
        $this->action($revision, 'approve')->assertOk()->assertJsonPath('data.approved_annual_id', $revision);
        $this->getJson($itemsPath, $this->headers)->assertOk()->assertJsonPath('data.0.id', $item)
            ->assertJsonPath('data.0.limit_minor', 9540)->assertJsonPath('data.0.committed_minor', 6540);
        $this->getJson(self::API."/requests/$first", $this->headers)->assertOk()->assertJsonPath('data.annual_version_id', $annual);

        $this->getJson('/api/budget/workspace-periods', $tenant['headers'])->assertOk()
            ->assertJsonPath('data.periods.0.limit_minor', '9540')->assertJsonPath('data.periods.0.committed_minor', '6540');
    }

    public function test_accounting_and_workspace_cannot_open_overlapping_sources_even_after_subscription_expires(): void
    {
        $tenant = $this->setUpWorkspace();
        $legacyData = ['name' => 'Historis', 'fiscal_year' => 2025, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31'];
        $legacy = $this->postJson('/api/budget-periods', $legacyData, $this->headers)->assertCreated()->json('data.id');
        $periodData = ['name' => 'Anggaran pilot', 'period_from' => '2025-12-01'];
        $this->postJson(self::API.'/periods', $periodData, $this->headers)->assertUnprocessable()->assertJsonPath('code', 'ACCOUNTING_BUDGET_PERIOD_EXISTS');
        $period = $this->createPeriod('2026-01-01');
        $this->postJson(self::API.'/periods', ['name' => 'Tumpang tindih', 'period_from' => '2026-09-01'], $this->headers)
            ->assertUnprocessable()->assertJsonPath('code', 'WORKSPACE_PERIOD_OVERLAP');
        $overlap = ['name' => 'Pagu kedua', 'fiscal_year' => 2026, 'period_from' => 'January 1 2026', 'period_to' => '2026-12-31'];
        $this->postJson('/api/budget-periods', $overlap, $this->headers)->assertUnprocessable()->assertJsonPath('code', 'WORKSPACE_IS_BUDGET_SOURCE');
        $this->putJson("/api/budget-periods/$legacy", $overlap, $this->headers)->assertUnprocessable()->assertJsonPath('code', 'WORKSPACE_IS_BUDGET_SOURCE');
        $this->getJson("/api/budget-periods/$legacy", $this->headers)->assertOk()->assertJsonPath('data.fiscal_year', 2025);
        $this->getJson('/api/budget/workspace-periods', $this->headers)->assertOk()->assertJsonPath('data.periods.0.id', $period);
        Subscription::query()->where('user_id', $tenant['user']->id)->where('product', 'workspace')->update(['ends_at' => now()->subMonths(2)]);
        $this->getJson(self::API.'/periods', $this->headers)->assertOk();
        $this->postJson(self::API.'/periods', ['name' => 'Periode baru', 'period_from' => '2027-01-01'], $this->headers)->assertStatus(402);
        $this->postJson('/api/budget-periods', $overlap, $this->headers)->assertUnprocessable()->assertJsonPath('code', 'WORKSPACE_IS_BUDGET_SOURCE');
    }

    public function test_permissions_limit_unit_access_and_keep_finance_approval_separate(): void
    {
        $tenant = $this->setUpWorkspace();
        $period = $this->createPeriod('2026-01-01');
        $unit = $this->createUnit('A');
        $otherUnit = $this->createUnit('B');
        $otherAnnual = $this->createBudget($this->annual($period, $otherUnit, '100'));
        $staff = User::factory()->create(['status' => 'active']);
        $membership = CompanyUser::query()->create([
            'company_id' => $tenant['company']->id, 'user_id' => $staff->id, 'status' => 'active', 'role' => 'viewer', 'full_access' => false,
        ]);
        $this->assignTestPermissions($membership, ['journal.view', 'workspace.units.view']);
        $this->putJson('/api/workspace/team/'.$membership->id.'/budget-access', ['permissions' => [BudgetPermission::SUBMIT]], $this->headers)->assertOk();
        $this->assertEqualsCanonicalizing(['journal.view', 'workspace.units.view', BudgetPermission::SUBMIT], $membership->assignedPermissions()->pluck('key')->all());
        $this->putJson("/api/workspace/units/$unit/members/".$membership->id, [], $this->headers)->assertOk();

        Sanctum::actingAs($staff, ['client']);
        $this->getJson(self::API.'/units?kind=annual', $this->headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $unit);
        $this->getJson(self::API."/requests/$otherAnnual", $this->headers)->assertNotFound();
        $this->postJson(self::API.'/requests', $this->annual($period, $otherUnit, '100'), $this->headers)->assertNotFound();
        $annual = $this->createBudget($this->annual($period, $unit, '100'));
        $this->action($annual, 'submit')->assertOk();
        $this->action($annual, 'approve')->assertForbidden();
        $this->putJson('/api/workspace/team/'.$membership->id.'/budget-access', ['permissions' => [BudgetPermission::APPROVE_ANNUAL]], $this->headers)->assertForbidden();

        $this->assignTestPermissions($membership, [BudgetPermission::APPROVE_ACTIVITY]);
        $this->action($annual, 'approve')->assertForbidden();
        $this->assignTestPermissions($membership, [BudgetPermission::APPROVE_ANNUAL]);
        $this->action($annual, 'approve')->assertOk();
    }

    private function setUpWorkspace(): array
    {
        $tenant = $this->setUpTenant();
        $this->setUpProductTenant($tenant['company'], 'workspace', 'workspace_pilot');
        $this->headers = $tenant['headers'];

        return $tenant;
    }

    private function createPeriod(string $from): int
    {
        return $this->postJson(self::API.'/periods', ['name' => 'Pagu pilot', 'period_from' => $from], $this->headers)->assertCreated()->json('data.id');
    }

    private function createUnit(string $code): int
    {
        return $this->postJson('/api/workspace/units', ['code' => $code, 'name' => 'Unit '.$code, 'is_active' => true], $this->headers)->assertCreated()->json('data.id');
    }

    private function createBudget(array $payload): int
    {
        return $this->postJson(self::API.'/requests', $payload, $this->headers)->assertCreated()->json('data.id');
    }

    private function annual(int $period, int $unit, string $amount): array
    {
        return [
            'kind' => 'annual', 'period_id' => $period, 'unit_id' => $unit, 'title' => 'RAB Tahunan',
            'lines' => [['code' => 'trans', 'description' => 'Transportasi', 'amount' => $amount]],
        ];
    }

    private function activity(int $period, int $unit, int $item, string $amount): array
    {
        return [
            'kind' => 'activity', 'period_id' => $period, 'unit_id' => $unit, 'title' => 'Kunjungan lapangan', 'activity_date' => '2026-07-10',
            'lines' => [['item_id' => $item, 'description' => 'Mobil', 'amount' => $amount]],
        ];
    }

    private function action(int $id, string $action, ?string $note = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::API."/requests/$id/$action", $note === null ? [] : ['note' => $note], $this->headers);
    }
}
