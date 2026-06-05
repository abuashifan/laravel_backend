<?php

namespace Tests\Feature\MasterData;

use App\Models\Tenant\Project;

class ProjectTest extends MasterDataTestCase
{
    public function test_unauthenticated_cannot_list_projects(): void
    {
        $res = $this->getJson('/api/master-data/projects');
        $res->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant(role: 'owner');

        $res = $this->getJson('/api/master-data/projects');
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'X_COMPANY_ID_REQUIRED');
    }

    public function test_create_duplicate_code_rejected_end_date_validation_update_and_deactivate(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $project = $this->postJson('/api/master-data/projects', [
            'code' => 'PRJ-01',
            'name' => 'Campaign',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->postJson('/api/master-data/projects', [
            'code' => 'PRJ-01',
            'name' => 'Dup',
        ], $ctx['headers'])->assertStatus(422);

        $this->postJson('/api/master-data/projects', [
            'code' => 'PRJ-02',
            'name' => 'Bad Date',
            'start_date' => '2026-05-10',
            'end_date' => '2026-05-01',
        ], $ctx['headers'])->assertStatus(422);

        $this->patchJson('/api/master-data/projects/'.$project['id'], [
            'name' => 'Campaign Updated',
        ], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.name', 'Campaign Updated');

        $this->patchJson('/api/master-data/projects/'.$project['id'].'/deactivate', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_active_project_usable_and_completed_not_usable(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $active = $this->postJson('/api/master-data/projects', [
            'code' => 'PRJ-A',
            'name' => 'Active Project',
            'status' => 'active',
            'is_active' => true,
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $p1 = Project::query()->findOrFail((int) $active['id']);
        $this->assertTrue($p1->isUsable());

        $completed = $this->postJson('/api/master-data/projects', [
            'code' => 'PRJ-C',
            'name' => 'Completed Project',
            'status' => 'completed',
            'is_active' => true,
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $p2 = Project::query()->findOrFail((int) $completed['id']);
        $this->assertFalse($p2->isUsable());
    }

    public function test_user_without_permission_cannot_create_project(): void
    {
        $ctx = $this->setUpTenant(role: 'viewer');

        $res = $this->postJson('/api/master-data/projects', [
            'code' => 'PRJ-X',
            'name' => 'No Permission',
        ], $ctx['headers']);

        $res->assertStatus(403);
        $res->assertJsonPath('code', 'PERMISSION_DENIED');
    }
}

