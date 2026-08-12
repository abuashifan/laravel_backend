<?php

namespace Tests\Feature\Subscription;

use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\Settings\Services\CompanySettingService;
use App\Shared\Models\Company;
use App\Shared\Models\Plan;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Subscription\PlanPermissionResolver;
use App\Shared\Tenant\TenantConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Alur nyata setelah gerbang menyala — bukan satu endpoint per izin (itu
 * `PlanFeatureMatrixTest`), tapi rangkaian lengkap. Empat baris pertama adalah
 * intinya: client Basic tetap harus bisa menutup bukunya dengan benar. Itu
 * janji terpenting di seluruh rencana subscription-tiers.
 */
class PlanSmokeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $tenantPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTierPlans();
        $this->enforcePlanFeatures(true);
    }

    protected function tearDown(): void
    {
        foreach ($this->tenantPaths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * Client Basic membuat jurnal draft lalu memostingnya. `journal.*` tidak
     * disebut di `config/plan_features.php` sama sekali — pembukuan inti
     * TIDAK DIPETAKAN, terbuka di semua tier (lihat peta Fase 2 §1).
     */
    public function test_basic_tier_can_create_and_post_a_journal(): void
    {
        $ctx = $this->provisionTenant('basic');

        $create = $this->postJson('/api/journals', [
            'journal_date' => now()->toDateString(),
            'description' => 'Smoke test Basic',
            'lines' => [
                ['account_id' => $ctx['accounts']['debit'], 'debit' => 150],
                ['account_id' => $ctx['accounts']['credit'], 'credit' => 150],
            ],
        ], $ctx['headers']);
        $create->assertStatus(201);
        $create->assertJsonPath('data.status', 'draft');

        $post = $this->postJson('/api/journals/'.$create->json('data.id').'/post', [], $ctx['headers']);
        $post->assertStatus(200);
        $post->assertJsonPath('data.status', 'posted');
    }

    public function test_basic_tier_cannot_create_a_second_warehouse(): void
    {
        $ctx = $this->provisionTenant('basic');

        $res = $this->postJson('/api/master-data/warehouses', [
            'code' => 'GDG-02', 'name' => 'Gudang Kedua',
        ], $ctx['headers']);

        $res->assertStatus(403);
        $res->assertJsonPath('code', 'FEATURE_NOT_IN_PLAN');
        $this->assertArrayHasKey('upgrade_url', $res->json('meta'));
    }

    public function test_pro_tier_can_create_a_second_warehouse(): void
    {
        $ctx = $this->provisionTenant('pro');

        $res = $this->postJson('/api/master-data/warehouses', [
            'code' => 'GDG-02', 'name' => 'Gudang Kedua',
        ], $ctx['headers']);

        $res->assertStatus(201);
    }

    public function test_pro_tier_cannot_create_a_budget_period(): void
    {
        $ctx = $this->provisionTenant('pro');

        $res = $this->postJson('/api/budget-periods', [
            'name' => 'Anggaran 2027',
            'fiscal_year' => 2027,
            'period_from' => '2027-01-01',
            'period_to' => '2027-12-31',
        ], $ctx['headers']);

        $res->assertStatus(403);
        $res->assertJsonPath('code', 'FEATURE_NOT_IN_PLAN');
    }

    public function test_enterprise_tier_can_create_a_budget_period(): void
    {
        $ctx = $this->provisionTenant('enterprise');

        $res = $this->postJson('/api/budget-periods', [
            'name' => 'Anggaran 2027',
            'fiscal_year' => 2027,
            'period_from' => '2027-01-01',
            'period_to' => '2027-12-31',
        ], $ctx['headers']);

        $res->assertStatus(201);
    }

    /**
     * Turun Enterprise → Pro, lalu baca dimensi lama tetap lolos — hak baca
     * tidak dicabut. Membuat dimensi baru tetap ditahan.
     */
    public function test_downgrade_from_enterprise_to_pro_keeps_read_access_but_blocks_new_writes(): void
    {
        $ctx = $this->provisionTenant('enterprise');

        $created = $this->postJson('/api/master-data/departments', [
            'code' => 'DEPT-01', 'name' => 'Cabang Jakarta',
        ], $ctx['headers']);
        $created->assertStatus(201);

        // Turunkan paket client, TANPA menyentuh baris departemen yang sudah dibuat.
        $ctx['owner']->forceFill(['plan_id' => Plan::query()->where('code', 'pro')->value('id')])->save();
        app(PlanPermissionResolver::class)->flush();

        $list = $this->getJson('/api/master-data/departments', $ctx['headers']);
        $list->assertStatus(200);
        $this->assertContains('DEPT-01', collect($list->json('data'))->pluck('code')->all());

        $newDept = $this->postJson('/api/master-data/departments', [
            'code' => 'DEPT-02', 'name' => 'Cabang Surabaya',
        ], $ctx['headers']);
        $newDept->assertStatus(403);
        $newDept->assertJsonPath('code', 'FEATURE_NOT_IN_PLAN');
    }

    /**
     * @return array{owner: User, company: Company, headers: array<string,string>, accounts: array{debit:int,credit:int}}
     */
    private function provisionTenant(string $planCode): array
    {
        $owner = $this->clientOn($planCode);
        $company = $this->companyOwnedBy($owner);

        $tenantDatabase = $company->tenantDatabase ?? TenantDatabase::query()
            ->where('company_id', $company->id)->firstOrFail();
        $this->tenantPaths[] = $tenantDatabase->database_path;

        app(TenantConnectionManager::class)->connect($tenantDatabase->database_path);

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        app(CompanySettingService::class)->updateAccountingSetting($company, [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $cash = ChartOfAccount::query()->create([
            'account_code' => '1000', 'account_name' => 'Cash', 'account_type' => 'asset',
            'normal_balance' => 'debit', 'is_cash_bank' => true, 'is_active' => true, 'is_system_default' => false,
        ]);
        $revenue = ChartOfAccount::query()->create([
            'account_code' => '4000', 'account_name' => 'Revenue', 'account_type' => 'revenue',
            'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false,
        ]);

        Sanctum::actingAs($owner, ['*']);

        return [
            'owner' => $owner,
            'company' => $company,
            'headers' => ['X-Company-ID' => (string) $company->id],
            'accounts' => ['debit' => $cash->id, 'credit' => $revenue->id],
        ];
    }
}
