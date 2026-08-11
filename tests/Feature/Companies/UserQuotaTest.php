<?php

namespace Tests\Feature\Companies;

use App\Modules\Companies\Services\CompanyUserAssignmentService;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Plan;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Subscription\UserQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Batas jumlah user berlaku per perusahaan, dibaca dari paket pemiliknya.
 */
class UserQuotaTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $tenantFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tenantFiles as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        parent::tearDown();
    }

    public function test_owner_counts_toward_the_limit(): void
    {
        // Free: max_users 1, artinya pemilik bekerja sendirian.
        $plan = $this->plan('free', 'Free', maxUsers: 1);
        [$owner, $company] = $this->seedCompany($plan);
        $staff = User::factory()->create(['status' => 'active']);

        $this->assertSame(1, app(UserQuotaService::class)->usedCount($company));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Kuota user perusahaan ini sudah penuh/');

        $this->assign($company, $staff, 'staff');

        $this->assertNotNull($owner->id);
    }

    public function test_users_can_be_added_up_to_the_plan_limit(): void
    {
        $plan = $this->plan('basic', 'Basic', maxUsers: 3);
        [, $company] = $this->seedCompany($plan);

        // Owner sudah mengisi satu slot, jadi tersisa dua.
        foreach (['a', 'b'] as $suffix) {
            $user = User::factory()->create(['status' => 'active', 'email' => "staf-{$suffix}@client.com"]);
            $this->assign($company, $user, 'staff');
        }

        $this->assertSame(3, CompanyUser::query()->where('company_id', $company->id)->where('status', 'active')->count());

        $extra = User::factory()->create(['status' => 'active']);

        $this->expectException(InvalidArgumentException::class);
        $this->assign($company, $extra, 'staff');
    }

    public function test_limit_applies_per_company_not_across_all_companies(): void
    {
        $plan = $this->plan('basic', 'Basic', maxUsers: 2);
        [$owner, $companyA] = $this->seedCompany($plan);
        [, $companyB] = $this->seedCompany($plan, $owner);

        $staffA = User::factory()->create(['status' => 'active', 'email' => 'a@client.com']);
        $staffB = User::factory()->create(['status' => 'active', 'email' => 'b@client.com']);

        // Kuota perusahaan A yang penuh tidak menghalangi perusahaan B.
        $this->assign($companyA, $staffA, 'staff');
        $this->assign($companyB, $staffB, 'staff');

        $this->assertSame(2, CompanyUser::query()->where('company_id', $companyA->id)->count());
        $this->assertSame(2, CompanyUser::query()->where('company_id', $companyB->id)->count());
    }

    public function test_changing_role_of_active_user_is_not_blocked_when_full(): void
    {
        $plan = $this->plan('basic', 'Basic', maxUsers: 2);
        [, $company] = $this->seedCompany($plan);
        $staff = User::factory()->create(['status' => 'active']);

        $this->assign($company, $staff, 'staff');

        // Perusahaan sudah penuh, tapi mengubah role tidak menambah siapa pun.
        $assignment = $this->assign($company, $staff, 'admin');

        $this->assertSame('admin', $assignment->role);
    }

    public function test_reactivating_an_inactive_user_is_blocked_when_full(): void
    {
        $plan = $this->plan('basic', 'Basic', maxUsers: 2);
        [, $company] = $this->seedCompany($plan);

        $left = User::factory()->create(['status' => 'active', 'email' => 'keluar@client.com']);
        CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $left->id,
            'role' => 'staff',
            'status' => 'inactive',
            'joined_at' => now(),
        ]);

        $replacement = User::factory()->create(['status' => 'active', 'email' => 'pengganti@client.com']);
        $this->assign($company, $replacement, 'staff');

        // Slot sudah dipakai pengganti; menghidupkan kembali yang lama menambah
        // user aktif, jadi ikut ditahan.
        $this->expectException(InvalidArgumentException::class);
        $this->assign($company, $left, 'staff');
    }

    public function test_custom_tier_uses_manual_user_quota(): void
    {
        $custom = $this->plan(Plan::CUSTOM_CODE, 'Custom', maxUsers: 2);
        [$owner, $company] = $this->seedCompany($custom);
        $owner->forceFill(['user_quota' => 4])->save();

        foreach (['a', 'b', 'c'] as $suffix) {
            $user = User::factory()->create(['status' => 'active', 'email' => "staf-{$suffix}@client.com"]);
            $this->assign($company->fresh(), $user, 'staff');
        }

        $this->assertSame(4, CompanyUser::query()->where('company_id', $company->id)->count());

        $extra = User::factory()->create(['status' => 'active']);
        $this->expectException(InvalidArgumentException::class);
        $this->assign($company->fresh(), $extra, 'staff');
    }

    private function assign(Company $company, User $user, string $role): CompanyUser
    {
        return app(CompanyUserAssignmentService::class)->assign([
            'company_id' => $company->id,
            'email' => $user->email,
            'role' => $role,
        ]);
    }

    private function plan(string $code, string $name, int $maxUsers): Plan
    {
        return Plan::query()->create([
            'name' => $name,
            'code' => $code,
            'max_users' => $maxUsers,
            'max_companies' => 5,
            'status' => 'active',
        ]);
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function seedCompany(Plan $plan, ?User $owner = null): array
    {
        $owner ??= User::factory()->create(['status' => 'active', 'plan_id' => $plan->id]);

        $company = Company::query()->create([
            'name' => 'PT Uji '.uniqid(),
            'slug' => 'pt-uji-'.uniqid(),
            'code' => 'CMP-'.substr((string) microtime(true), -6),
            'status' => 'active',
            'created_by' => $owner->id,
        ]);

        CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $databaseName = 'test_userquota_'.$company->id.'.sqlite';
        $databasePath = database_path('tenants/'.$databaseName);
        File::ensureDirectoryExists(dirname($databasePath));
        if (! File::exists($databasePath)) {
            File::put($databasePath, '');
        }
        $this->tenantFiles[] = $databasePath;

        TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => $databaseName,
            'database_path' => $databasePath,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        return [$owner, $company];
    }
}
