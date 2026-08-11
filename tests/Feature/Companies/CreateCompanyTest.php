<?php

namespace Tests\Feature\Companies;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreateCompanyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $tenantFiles = [];

    private string $tenantDirectory = '';

    /**
     * Provisioning menamai file tenant dari company_id (`company_000001.sqlite`),
     * sementara RefreshDatabase membuat id selalu mulai dari 1. Dijalankan di
     * folder tenant sungguhan, nama itu bentrok dengan tenant dev yang ada.
     * Karena itu tiap test dapat direktori sendiri.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDirectory = storage_path('framework/testing/tenants-'.uniqid());
        File::ensureDirectoryExists($this->tenantDirectory);
        config(['tenant.database_path' => $this->tenantDirectory]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tenantFiles as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        if ($this->tenantDirectory !== '' && File::isDirectory($this->tenantDirectory)) {
            File::deleteDirectory($this->tenantDirectory);
        }

        parent::tearDown();
    }

    public function test_unauthenticated_user_cannot_create_company(): void
    {
        $this->postJson('/api/companies', ['name' => 'PT Maju Jaya'])
            ->assertStatus(401);
    }

    public function test_self_registration_route_is_closed(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Orang Luar',
            'email' => 'luar@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(404);
    }

    public function test_authenticated_user_can_create_company_and_becomes_owner(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/companies', ['name' => 'PT Maju Jaya'])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'PT Maju Jaya')
            ->assertJsonPath('data.slug', 'pt-maju-jaya')
            ->assertJsonPath('data.user_role', 'owner')
            ->assertJsonPath('data.tenant_database.status', 'active');

        $companyId = (int) $response->json('data.id');

        $this->assertDatabaseHas('company_users', [
            'company_id' => $companyId,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $tenantDatabase = TenantDatabase::query()->where('company_id', $companyId)->firstOrFail();
        $this->trackTenantFile($tenantDatabase->database_path);

        $this->assertTrue(File::exists($tenantDatabase->database_path));
        $this->assertSame('CMP-'.str_pad((string) $companyId, 6, '0', STR_PAD_LEFT), $response->json('data.code'));
    }

    public function test_created_company_tenant_database_is_migrated_and_selectable(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $companyId = (int) $this->postJson('/api/companies', ['name' => 'PT Migrasi'])
            ->assertStatus(201)
            ->json('data.id');

        $this->trackTenantFile(
            TenantDatabase::query()->where('company_id', $companyId)->firstOrFail()->database_path
        );

        // select() hanya lolos kalau tenant database-nya aktif; setelahnya
        // konteks tenant terikat ke file baru dan skema hasil migrasi bisa
        // dibaca lewat endpoint yang dijaga company.access.
        $this->postJson('/api/companies/select', ['company_id' => $companyId])
            ->assertStatus(200)
            ->assertJsonPath('data.active_company.id', $companyId);

        $this->getJson('/api/setup/status', ['X-Company-ID' => (string) $companyId])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_slug_collision_gets_numeric_suffix(): void
    {
        $existingOwner = User::factory()->create(['status' => 'active']);
        Company::query()->create([
            'name' => 'PT Maju Jaya',
            'slug' => 'pt-maju-jaya',
            'code' => 'CMP-009999',
            'status' => 'active',
            'created_by' => $existingOwner->id,
        ]);

        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/companies', ['name' => 'PT Maju Jaya'])
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'pt-maju-jaya-2');

        $this->trackTenantFile(
            TenantDatabase::query()->where('company_id', (int) $response->json('data.id'))->firstOrFail()->database_path
        );
    }

    public function test_duplicate_name_for_same_user_is_rejected_without_creating_second_tenant(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $companyId = (int) $this->postJson('/api/companies', ['name' => 'PT Maju Jaya'])
            ->assertStatus(201)
            ->json('data.id');

        $this->trackTenantFile(
            TenantDatabase::query()->where('company_id', $companyId)->firstOrFail()->database_path
        );

        $this->postJson('/api/companies', ['name' => 'pt maju jaya'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['name']]);

        $this->assertSame(1, CompanyUser::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, TenantDatabase::query()->count());
    }

    public function test_name_is_validated(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $this->postJson('/api/companies', ['name' => ''])->assertStatus(422);
        $this->postJson('/api/companies', ['name' => 'PT'])->assertStatus(422);

        $this->assertSame(0, Company::query()->count());
    }

    public function test_created_company_is_isolated_from_other_users(): void
    {
        $userA = User::factory()->create(['status' => 'active']);
        $userB = User::factory()->create(['status' => 'active']);

        Sanctum::actingAs($userA);
        $companyAId = (int) $this->postJson('/api/companies', ['name' => 'PT Punya A'])
            ->assertStatus(201)
            ->json('data.id');
        $tenantA = TenantDatabase::query()->where('company_id', $companyAId)->firstOrFail();
        $this->trackTenantFile($tenantA->database_path);

        Sanctum::actingAs($userB);
        $companyBId = (int) $this->postJson('/api/companies', ['name' => 'PT Punya B'])
            ->assertStatus(201)
            ->json('data.id');
        $tenantB = TenantDatabase::query()->where('company_id', $companyBId)->firstOrFail();
        $this->trackTenantFile($tenantB->database_path);

        // Data A dan B tidak mungkin bercampur: file databasenya berbeda.
        $this->assertNotSame($tenantA->database_name, $tenantB->database_name);
        $this->assertNotSame($tenantA->database_path, $tenantB->database_path);

        // B hanya melihat perusahaannya sendiri di picker.
        $companyIds = array_map(
            fn ($row) => $row['id'],
            $this->getJson('/api/companies')->assertStatus(200)->json('data')
        );
        $this->assertSame([$companyBId], $companyIds);

        // B tidak bisa membuka perusahaan A, baik lewat select maupun lewat
        // header X-Company-ID langsung ke endpoint data bisnis.
        $this->postJson('/api/companies/select', ['company_id' => $companyAId])
            ->assertStatus(403);

        $this->getJson('/api/setup/status', ['X-Company-ID' => (string) $companyAId])
            ->assertStatus(403);
    }

    private function trackTenantFile(?string $path): void
    {
        if (is_string($path) && $path !== '') {
            $this->tenantFiles[] = $path;
        }
    }
}
