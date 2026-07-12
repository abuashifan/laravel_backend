<?php

namespace Tests\Feature\Reports;

use App\Shared\Models\CompanyUser;
use App\Shared\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Journal\JournalTestCase;

class SavedReportTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/reports/saved')->assertStatus(401);
    }

    public function test_permission_denied(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/reports/saved', $ctx['headers'])->assertStatus(403);
    }

    public function test_invalid_report_key_returns_422(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $this->postJson('/api/reports/saved', [
            'report_key' => 'not-a-real-report',
            'name' => 'X',
            'params' => [],
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_crud_lifecycle(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        // Create.
        $created = $this->postJson('/api/reports/saved', [
            'report_key' => 'general-ledger',
            'name' => 'GL Juni',
            'params' => ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'],
        ], $ctx['headers'])->assertStatus(201);
        $id = $created->json('data.id');
        $this->assertTrue($created->json('data.is_owner'));
        $this->assertSame('2026-06-01', $created->json('data.params.start_date'));

        // Index shows it.
        $list = $this->getJson('/api/reports/saved', $ctx['headers'])->assertStatus(200);
        $this->assertCount(1, $list->json('data'));

        // Update.
        $this->putJson("/api/reports/saved/{$id}", [
            'report_key' => 'general-ledger',
            'name' => 'GL Juni (revisi)',
            'params' => ['start_date' => '2026-06-01', 'end_date' => '2026-06-15'],
        ], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.name', 'GL Juni (revisi)');

        // Delete.
        $this->deleteJson("/api/reports/saved/{$id}", [], $ctx['headers'])->assertStatus(200);
        $this->getJson('/api/reports/saved', $ctx['headers'])->assertJsonCount(0, 'data');
    }

    public function test_share_makes_report_visible_to_recipients_read_only(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $recipient = $this->addCompanyUser($ctx['company']->id, 'finance');

        // Owner creates a report shared with the recipient.
        $created = $this->postJson('/api/reports/saved', [
            'report_key' => 'balance-sheet',
            'name' => 'Neraca Tim',
            'params' => ['as_of_date' => '2026-06-30'],
            'shared_user_ids' => [$recipient->id],
        ], $ctx['headers'])->assertStatus(201);
        $id = $created->json('data.id');
        $this->assertSame([$recipient->id], $created->json('data.shared_user_ids'));

        // Recipient sees it (read-only): is_owner=false.
        Sanctum::actingAs($recipient);
        $list = $this->getJson('/api/reports/saved', $ctx['headers'])->assertStatus(200);
        $this->assertCount(1, $list->json('data'));
        $this->assertFalse($list->json('data.0.is_owner'));

        // Recipient cannot modify or delete.
        $this->putJson("/api/reports/saved/{$id}", [
            'report_key' => 'balance-sheet',
            'name' => 'hijack',
        ], $ctx['headers'])->assertStatus(403);
        $this->deleteJson("/api/reports/saved/{$id}", [], $ctx['headers'])->assertStatus(403);
    }

    public function test_non_shared_user_cannot_see_report(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $stranger = $this->addCompanyUser($ctx['company']->id, 'finance');

        $this->postJson('/api/reports/saved', [
            'report_key' => 'profit-loss',
            'name' => 'Privat',
            'params' => [],
        ], $ctx['headers'])->assertStatus(201);

        Sanctum::actingAs($stranger);
        $this->getJson('/api/reports/saved', $ctx['headers'])->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_shareable_users_lists_other_active_company_users(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $other = $this->addCompanyUser($ctx['company']->id, 'finance');

        $res = $this->getJson('/api/reports/saved/shareable-users', $ctx['headers'])->assertStatus(200);
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($other->id, $ids);
        $this->assertNotContains($ctx['user']->id, $ids); // pemilik/aktif user dikecualikan
    }

    private function addCompanyUser(int $companyId, string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        CompanyUser::query()->create([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }
}
