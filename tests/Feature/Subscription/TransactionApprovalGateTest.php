<?php

namespace Tests\Feature\Subscription;

use App\Shared\Models\CompanyUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Alur persetujuan transaksi (`transaction_workflow_mode = draft_approve_post`)
 * digerbangi di validasi request, BUKAN di peta izin — ini nilai pengaturan,
 * bukan izin (Fase 2, skema tier §2 "Alur persetujuan transaksi").
 */
class TransactionApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTierPlans();
        $this->enforcePlanFeatures(true);
    }

    public function test_basic_tier_cannot_switch_to_draft_approve_post(): void
    {
        $company = $this->companyOwnedBy($this->clientOn('basic'));
        Sanctum::actingAs(CompanyUser::query()->where('company_id', $company->id)->firstOrFail()->user, ['*']);

        $res = $this->patchJson('/api/settings/company/accounting', [
            'transaction_workflow_mode' => 'draft_approve_post',
            'approval_enabled' => true,
        ], ['X-Company-ID' => (string) $company->id]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('transaction_workflow_mode');
    }

    public function test_pro_tier_can_switch_to_draft_approve_post(): void
    {
        $company = $this->companyOwnedBy($this->clientOn('pro'));
        Sanctum::actingAs(CompanyUser::query()->where('company_id', $company->id)->firstOrFail()->user, ['*']);

        $res = $this->patchJson('/api/settings/company/accounting', [
            'transaction_workflow_mode' => 'draft_approve_post',
            'approval_enabled' => true,
        ], ['X-Company-ID' => (string) $company->id]);

        $res->assertStatus(200);
        $res->assertJsonPath('data.accounting.transaction_workflow_mode', 'draft_approve_post');
    }

    public function test_basic_tier_can_still_use_draft_then_post(): void
    {
        // Turun tier tidak mencabut yang sudah dipakai — hanya menahan
        // MEMILIHNYA LAGI. `draft_then_post` bukan draft_approve_post,
        // jadi ini harus tetap lolos di semua tier.
        $company = $this->companyOwnedBy($this->clientOn('basic'));
        Sanctum::actingAs(CompanyUser::query()->where('company_id', $company->id)->firstOrFail()->user, ['*']);

        $res = $this->patchJson('/api/settings/company/accounting', [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ], ['X-Company-ID' => (string) $company->id]);

        $res->assertStatus(200);
        $res->assertJsonPath('data.accounting.transaction_workflow_mode', 'draft_then_post');
    }

    public function test_switch_off_lets_basic_tier_use_draft_approve_post_too(): void
    {
        $this->enforcePlanFeatures(false);

        $company = $this->companyOwnedBy($this->clientOn('basic'));
        Sanctum::actingAs(CompanyUser::query()->where('company_id', $company->id)->firstOrFail()->user, ['*']);

        $res = $this->patchJson('/api/settings/company/accounting', [
            'transaction_workflow_mode' => 'draft_approve_post',
            'approval_enabled' => true,
        ], ['X-Company-ID' => (string) $company->id]);

        $res->assertStatus(200);
    }
}
