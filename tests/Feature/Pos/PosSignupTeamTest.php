<?php

namespace Tests\Feature\Pos;

use App\Mail\CompanyInvitationMail;
use App\Mail\PosVerifyEmailMail;
use App\Modules\Pos\Controllers\PosSignupController;
use App\Shared\Models\CompanyInvitation;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Subscription;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Fase 2 POS: daftar mandiri → verifikasi email (trial) → buat toko POS-only
 * → undang kasir → mode hanya-baca saat langganan habis.
 */
class PosSignupTeamTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDirectory = storage_path('framework/testing/pos-signup-'.uniqid(getmypid().'_', true));
        File::ensureDirectoryExists($this->tenantDirectory);
        config(['tenant.database_path' => $this->tenantDirectory, 'tenant.driver' => 'sqlite']);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        DB::disconnect('tenant');
        DB::disconnect('pos');
        if ($this->tenantDirectory !== '' && File::isDirectory($this->tenantDirectory)) {
            File::deleteDirectory($this->tenantDirectory);
        }

        parent::tearDown();
    }

    /** Beberapa pengguna bergantian lewat token Bearer: jangan pakai user guard dari request sebelumnya. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    public function test_signup_verify_then_create_pos_only_store(): void
    {
        $response = $this->postJson('/api/pos/register', [
            'name' => 'Bu Tini', 'email' => 'Tini@Warung.test',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ])->assertCreated()->assertJsonPath('data.mail_sent', true);

        $token = $response->json('data.token');
        $auth = ['Authorization' => 'Bearer '.$token];
        $user = User::query()->where('email', 'tini@warung.test')->firstOrFail();
        Mail::assertSent(PosVerifyEmailMail::class, fn (PosVerifyEmailMail $mail) => str_contains($mail->verifyUrl, '/verify-email?token='));

        // Belum verifikasi: tidak ada langganan, belum bisa membuat toko.
        $this->getJson('/api/pos/account', $auth)->assertOk()
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.subscription.state', 'none');
        $this->postJson('/api/companies', ['name' => 'Warung Tini', 'product' => 'pos'], $auth)
            ->assertStatus(403)->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        // Token rusak ditolak; token benar memulai trial (sekali saja).
        $this->postJson('/api/pos/verify-email', ['token' => 'rusak'])->assertStatus(422);
        $verifyToken = PosSignupController::verificationToken($user);
        $this->postJson('/api/pos/verify-email', ['token' => $verifyToken])->assertOk()
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.subscription.state', 'active')
            ->assertJsonPath('data.subscription.is_trial', true);
        $this->postJson('/api/pos/verify-email', ['token' => $verifyToken])->assertOk();
        $this->assertSame(1, Subscription::query()->where('user_id', $user->id)->where('product', 'pos')->count());
        $this->assertNull($user->refresh()->plan_id, 'Trial Kasir tidak boleh memberi paket akuntansi.');

        $companyId = $this->postJson('/api/companies', ['name' => 'Warung Tini', 'product' => 'pos'], $auth)
            ->assertCreated()->json('data.id');
        $this->assertFalse(TenantDatabase::forProduct('accounting')->where('company_id', $companyId)->exists());

        $headers = $auth + ['X-Company-ID' => (string) $companyId];
        $this->getJson('/api/pos/bootstrap', $headers)->assertOk()
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.subscription.is_trial', true)
            ->assertJsonPath('data.subscription.read_only', false);
        $this->postJson('/api/pos/products', ['name' => 'Teh Botol', 'price' => 5000], $headers)->assertCreated();

        // Login lewat aplikasi kasir tidak dikunci status langganan akuntansi.
        $this->postJson('/api/auth/login', ['email' => 'tini@warung.test', 'password' => 'rahasia123', 'app' => 'pos'])->assertOk();
    }

    public function test_duplicate_email_and_weak_password_rejected(): void
    {
        User::factory()->create(['email' => 'ada@warung.test']);

        $this->postJson('/api/pos/register', [
            'name' => 'X', 'email' => 'ada@warung.test', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/pos/register', [
            'name' => 'X', 'email' => 'baru@warung.test', 'password' => 'pendek', 'password_confirmation' => 'pendek',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_expired_subscription_makes_store_read_only(): void
    {
        [$owner, $headers] = $this->verifiedOwnerWithStore();
        $this->postJson('/api/pos/products', ['name' => 'Kopi', 'price' => 3000], $headers)->assertCreated();

        Subscription::query()->where('user_id', $owner->id)->update([
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subDays(30),
        ]);

        $this->getJson('/api/pos/products', $headers)->assertOk()->assertJsonPath('data.total', 1);
        $this->postJson('/api/pos/products', ['name' => 'Gula', 'price' => 1000], $headers)
            ->assertStatus(402)->assertJsonPath('code', 'SUBSCRIPTION_INACTIVE');
        $this->getJson('/api/pos/bootstrap', $headers)->assertOk()
            ->assertJsonPath('data.subscription.state', 'expired')
            ->assertJsonPath('data.subscription.read_only', true);
        $this->postJson('/api/auth/login', ['email' => $owner->email, 'password' => 'rahasia123', 'app' => 'pos'])->assertOk();
    }

    public function test_owner_invites_cashier_with_template_and_quota(): void
    {
        [, $headers] = $this->verifiedOwnerWithStore();

        $invite = $this->postJson('/api/pos/team/invitations', ['email' => 'Kasir1@Warung.test', 'role' => 'kasir'], $headers)
            ->assertCreated()->assertJsonPath('data.mail_sent', true);
        $this->assertStringContainsString('/invite/', $invite->json('data.accept_url'));
        Mail::assertSent(CompanyInvitationMail::class);

        $invitation = CompanyInvitation::query()->findOrFail($invite->json('data.id'));
        $this->assertSame('pos', $invitation->product);
        $this->assertContains('pos.sales.create', $invitation->permissions);
        $this->assertNotContains('pos.sales.delete', $invitation->permissions);

        // Kasir membuat akun dari tautan undangan.
        $cashierToken = $this->postJson("/api/invitations/{$invitation->token}/register", [
            'name' => 'Kasir Satu', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ])->assertCreated()->json('data.token');
        $cashierHeaders = ['Authorization' => 'Bearer '.$cashierToken, 'X-Company-ID' => $headers['X-Company-ID']];

        $this->getJson('/api/pos/bootstrap', $cashierHeaders)->assertOk()->assertJsonPath('data.is_owner', false);
        $this->getJson('/api/pos/team', $cashierHeaders)->assertStatus(403);
        $this->postJson('/api/pos/products', ['name' => 'X', 'price' => 1], $cashierHeaders)->assertStatus(403);

        $team = $this->getJson('/api/pos/team', $headers)->assertOk();
        $this->assertSame(['owner', 'kasir'], array_column($team->json('data.members'), 'role'));
        $this->assertSame(3, $team->json('data.quota.limit'));

        // Paket Kasir: 3 pengguna (pemilik + kasir + 1 undangan menunggu).
        $this->postJson('/api/pos/team/invitations', ['email' => 'kasir2@warung.test', 'role' => 'kasir'], $headers)->assertCreated();
        $this->postJson('/api/pos/team/invitations', ['email' => 'kasir3@warung.test', 'role' => 'kasir'], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'USER_QUOTA_EXCEEDED');

        // Naik jadi kepala toko, lalu dinonaktifkan; pemilik dilindungi.
        $cashierMemberId = collect($team->json('data.members'))->firstWhere('role', 'kasir')['id'];
        $this->patchJson("/api/pos/team/members/{$cashierMemberId}", ['role' => 'kepala_toko'], $headers)->assertOk();
        $this->postJson('/api/pos/products', ['name' => 'Y', 'price' => 1], $cashierHeaders)->assertCreated();
        $this->patchJson("/api/pos/team/members/{$cashierMemberId}", ['status' => 'inactive'], $headers)->assertOk();
        $this->getJson('/api/pos/bootstrap', $cashierHeaders)->assertStatus(403);

        $ownerMemberId = CompanyUser::query()->where('company_id', $headers['X-Company-ID'])->where('is_owner', true)->value('id');
        $this->patchJson("/api/pos/team/members/{$ownerMemberId}", ['status' => 'inactive'], $headers)->assertStatus(422);
    }

    /** @return array{0: User, 1: array<string, string>} */
    private function verifiedOwnerWithStore(): array
    {
        $token = $this->postJson('/api/pos/register', [
            'name' => 'Pemilik', 'email' => 'pemilik'.uniqid().'@warung.test',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ])->assertCreated()->json('data.token');
        $owner = User::query()->latest('id')->firstOrFail();
        $this->postJson('/api/pos/verify-email', ['token' => PosSignupController::verificationToken($owner)])->assertOk();

        $auth = ['Authorization' => 'Bearer '.$token];
        $companyId = $this->postJson('/api/companies', ['name' => 'Toko Uji', 'product' => 'pos'], $auth)->assertCreated()->json('data.id');

        return [$owner, $auth + ['X-Company-ID' => (string) $companyId]];
    }
}
