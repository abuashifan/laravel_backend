<?php

namespace Tests\Feature\Journal;

use App\Models\Tenant\Department;
use App\Models\Tenant\JournalEntryLine;
use App\Models\Tenant\Project;

class JournalDimensionTest extends JournalTestCase
{
    public function test_create_journal_with_department_and_project_dimensions_and_reject_inactive_or_completed(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $dept = Department::query()->create([
            'code' => 'OPS',
            'name' => 'Operational',
            'is_active' => true,
        ]);

        $project = Project::query()->create([
            'code' => 'PRJ-01',
            'name' => 'Campaign',
            'status' => 'active',
            'is_active' => true,
        ]);

        $payload = [
            'journal_date' => now()->toDateString(),
            'description' => 'Journal With Dimensions',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 100, 'department_id' => $dept->id],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 100, 'project_id' => $project->id],
            ],
        ];

        $res = $this->postJson('/api/journals', $payload, $ctx['headers'])->assertStatus(201);
        $this->assertSame((int) $dept->id, (int) $res->json('data.lines.0.department_id'));
        $this->assertSame((int) $project->id, (int) $res->json('data.lines.1.project_id'));

        $lineWithDeptId = (int) $res->json('data.lines.0.id');
        $lineWithProjectId = (int) $res->json('data.lines.1.id');

        $lineWithDept = JournalEntryLine::query()->with('department')->findOrFail($lineWithDeptId);
        $this->assertSame((int) $dept->id, (int) $lineWithDept->department?->id);

        $lineWithProject = JournalEntryLine::query()->with('project')->findOrFail($lineWithProjectId);
        $this->assertSame((int) $project->id, (int) $lineWithProject->project?->id);

        $dept->is_active = false;
        $dept->save();

        $badDept = $this->postJson('/api/journals', [
            'journal_date' => now()->toDateString(),
            'description' => 'Bad Dept',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 10, 'department_id' => $dept->id],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 10],
            ],
        ], $ctx['headers'])->assertStatus(422);
        $badDept->assertJsonPath('code', 'VALIDATION_ERROR');

        $project->status = 'completed';
        $project->save();

        $badProject = $this->postJson('/api/journals', [
            'journal_date' => now()->toDateString(),
            'description' => 'Bad Project',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 10, 'project_id' => $project->id],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 10],
            ],
        ], $ctx['headers'])->assertStatus(422);
        $badProject->assertJsonPath('code', 'VALIDATION_ERROR');
    }
}
