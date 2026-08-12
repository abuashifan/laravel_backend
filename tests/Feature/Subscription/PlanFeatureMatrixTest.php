<?php

namespace Tests\Feature\Subscription;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Permission\EffectivePermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Matriks tier — definisi peta `config/plan_features.php` yang bisa
 * dieksekusi. Ubah peta tanpa menyentuh matriks ini, test gagal: perubahan
 * tier jadi keputusan sadar, bukan efek samping (Fase 2, skema tier).
 *
 * Semua pemeriksaan lewat `owner` (role `*`) supaya hanya lapis 1 (paket)
 * yang diuji di sini — variasi role/override adalah tanggung jawab
 * `PlanLayerBoundaryTest`.
 */
class PlanFeatureMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Company> kode tier => perusahaan uji, dibuat sekali */
    private array $companies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTierPlans();
        $this->enforcePlanFeatures(true);

        foreach (['basic', 'pro', 'enterprise'] as $code) {
            $this->companies[$code] = $this->companyOwnedBy($this->clientOn($code));
        }
    }

    /**
     * Sebelas kemampuan yang digerbangi peta, tulis: Basic tidak dapat
     * satu pun, Pro dapat empat pertama, Enterprise dapat semuanya.
     */
    public function test_gated_permissions_follow_the_approved_tier_map(): void
    {
        $expectations = [
            // izin => [basic, pro, enterprise]
            'warehouses.create' => [false, true, true],
            'warehouses.edit' => [false, true, true],
            'access.audit.view' => [false, true, true],
            'reports.save' => [false, true, true],
            'reports.multi_period' => [false, true, true],
            'budgets.submit' => [false, false, true],
            'budgets.manage' => [false, false, true],
            'departments.create' => [false, false, true],
            'projects.create' => [false, false, true],
            'access.roles.create' => [false, false, true],
            'access.permissions.manage' => [false, false, true],
            'transactions.import' => [false, true, true],
        ];

        foreach ($expectations as $permission => [$basic, $pro, $enterprise]) {
            $this->assertGate('basic', $permission, $basic);
            $this->assertGate('pro', $permission, $pro);
            $this->assertGate('enterprise', $permission, $enterprise);
        }
    }

    /**
     * Sebelas baris terbawah menjaga arah sebaliknya: izin `.view` dan izin
     * inti TIDAK PERNAH tertutup, di tier mana pun — turun tier tidak
     * mencabut hak baca (keputusan pemilik produk 2026-08-11).
     */
    public function test_read_and_core_permissions_are_never_gated(): void
    {
        $alwaysOpen = [
            'budgets.view',
            'warehouses.view',
            'departments.view',
            'projects.view',
            'access.roles.view',
            'access.permissions.view',
            'access.users.invite',
            'reports.view',
            'fixed_assets.view',
            'inventory.view',
            'journal.view',
            'dashboard.view',
            'masterdata.import',
        ];

        foreach (['basic', 'pro', 'enterprise'] as $tier) {
            foreach ($alwaysOpen as $permission) {
                $this->assertGate($tier, $permission, true);
            }
        }
    }

    private function assertGate(string $tier, string $permission, bool $expectedAllowed): void
    {
        $companyUser = CompanyUser::query()
            ->where('company_id', $this->companies[$tier]->id)
            ->firstOrFail();

        $allowed = app(EffectivePermissionService::class)->hasPermission($companyUser, $permission);

        $this->assertSame(
            $expectedAllowed,
            $allowed,
            sprintf(
                'Tier "%s" seharusnya %s izin "%s".',
                $tier,
                $expectedAllowed ? 'MEMBUKA' : 'MENUTUP',
                $permission,
            )
        );
    }
}
