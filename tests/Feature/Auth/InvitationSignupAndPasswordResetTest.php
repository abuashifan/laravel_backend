<?php

namespace Tests\Feature\Auth;

use App\Mail\CompanyInvitationMail;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyInvitation;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class InvitationSignupAndPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_invitation_emails_the_accept_link(): void
    {
        Mail::fake();
        $ctx = $this->companyContext();

        $data = $this->postJson('/api/access/invitations', ['email' => 'staff@example.test', 'full_access' => false, 'permissions' => ['sales.invoices.view']], $ctx['headers'])
            ->assertCreated()
            ->assertJsonPath('data.mail_sent', true)
            ->json('data');

        $this->assertSame(config('app.frontend_url').'/invitations/'.$data['token'].'/accept', $data['accept_url']);
        Mail::assertSent(CompanyInvitationMail::class, fn (CompanyInvitationMail $mail) => $mail->hasTo('staff@example.test')
            && $mail->companyName === $ctx['company']->name
            && CompanyInvitationMail::acceptUrl($mail->invitation) === $data['accept_url']);
    }

    public function test_invitation_is_kept_when_the_email_cannot_be_sent(): void
    {
        $ctx = $this->companyContext();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

        $this->postJson('/api/access/invitations', ['email' => 'staff@example.test', 'full_access' => true, 'permissions' => []], $ctx['headers'])
            ->assertCreated()
            ->assertJsonPath('data.mail_sent', false);

        $this->assertDatabaseHas('company_invitations', ['company_id' => $ctx['company']->id, 'email' => 'staff@example.test', 'status' => 'pending']);
    }

    public function test_invitation_link_creates_the_account_and_accepts_in_one_step(): void
    {
        $ctx = $this->companyContext();
        $invitation = $this->invitation($ctx['company'], 'newstaff@example.test', ['sales.invoices.view']);

        $this->getJson('/api/invitations/'.$invitation->token)
            ->assertOk()
            ->assertJsonPath('data.email', 'newstaff@example.test')
            ->assertJsonPath('data.company_name', $ctx['company']->name)
            ->assertJsonPath('data.has_account', false);

        $this->postJson('/api/invitations/'.$invitation->token.'/register', ['name' => 'Staf Baru', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['user', 'token', 'token_type']]);

        $user = User::query()->where('email', 'newstaff@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('rahasia123', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $member = CompanyUser::query()->where('company_id', $ctx['company']->id)->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('active', $member->status);
        $this->assertFalse($member->is_owner);
        $this->assertTrue($member->assignedPermissions()->where('permissions.key', 'sales.invoices.view')->exists());
        $this->assertSame('accepted', $invitation->refresh()->status);

        $this->postJson('/api/invitations/'.$invitation->token.'/register', ['name' => 'Lagi', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'])
            ->assertStatus(422)->assertJsonPath('code', 'INVITATION_INVALID');
    }

    public function test_invitation_link_does_not_create_a_second_account_for_an_existing_email(): void
    {
        $ctx = $this->companyContext();
        User::factory()->create(['email' => 'ada@example.test', 'status' => 'active']);
        $invitation = $this->invitation($ctx['company'], 'ada@example.test', []);

        $this->getJson('/api/invitations/'.$invitation->token)->assertOk()->assertJsonPath('data.has_account', true);
        $this->postJson('/api/invitations/'.$invitation->token.'/register', ['name' => 'Ada', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'])
            ->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_EXISTS');
        $this->assertSame(1, User::query()->where('email', 'ada@example.test')->count());
    }

    public function test_invitation_link_rejects_bad_input_and_unusable_invitations(): void
    {
        $ctx = $this->companyContext();
        $invitation = $this->invitation($ctx['company'], 'bad@example.test', []);
        $payload = ['name' => 'Bad', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'];

        $this->postJson('/api/invitations/'.$invitation->token.'/register', ['name' => 'Bad', 'password' => 'rahasia123', 'password_confirmation' => 'lain'])->assertStatus(422);
        $this->postJson('/api/invitations/'.$invitation->token.'/register', ['name' => 'Bad', 'password' => 'pendek', 'password_confirmation' => 'pendek'])->assertStatus(422);
        $this->postJson('/api/invitations/salah-token/register', $payload)->assertStatus(422)->assertJsonPath('code', 'INVITATION_INVALID');
        $this->getJson('/api/invitations/salah-token')->assertStatus(422)->assertJsonPath('code', 'INVITATION_INVALID');

        $invitation->forceFill(['expires_at' => now()->subDay()])->save();
        $this->postJson('/api/invitations/'.$invitation->token.'/register', $payload)->assertStatus(422)->assertJsonPath('code', 'INVITATION_INVALID');
        $invitation->forceFill(['expires_at' => now()->addDay(), 'status' => 'revoked'])->save();
        $this->postJson('/api/invitations/'.$invitation->token.'/register', $payload)->assertStatus(422)->assertJsonPath('code', 'INVITATION_INVALID');
        $this->assertDatabaseMissing('users', ['email' => 'bad@example.test']);
    }

    public function test_full_quota_leaves_no_orphan_account(): void
    {
        $ctx = $this->companyContext();
        $ctx['owner']->forceFill(['extra_users' => 0])->save();
        $invitation = $this->invitation($ctx['company'], 'quota@example.test', []);

        $this->postJson('/api/invitations/'.$invitation->token.'/register', ['name' => 'Kuota', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'])
            ->assertStatus(422)->assertJsonPath('code', 'USER_QUOTA_EXCEEDED');
        $this->assertDatabaseMissing('users', ['email' => 'quota@example.test']);
        $this->assertSame('pending', $invitation->refresh()->status);
    }

    public function test_forgot_password_emails_a_frontend_link_and_hides_unknown_addresses(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'lupa@example.test', 'status' => 'active']);

        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'lupa@example.test'])->assertOk();
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'tidakada@example.test'])->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, config('app.frontend_url').'/reset-password?token=')
                && str_contains($url, 'email='.urlencode('lupa@example.test'));
        });
        Notification::assertCount(1);
    }

    public function test_forgot_password_skips_inactive_and_platform_admin_accounts(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'off@example.test', 'status' => 'inactive']);
        User::factory()->create(['email' => 'admin@example.test', 'status' => 'active', 'is_platform_admin' => true]);

        $this->postJson('/api/auth/forgot-password', ['email' => 'off@example.test'])->assertOk();
        $this->postJson('/api/auth/forgot-password', ['email' => 'admin@example.test'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_reset_password_changes_the_password_and_revokes_sessions(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.test', 'status' => 'active', 'password' => 'lama12345']);
        $user->createToken('api-token');
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => 'reset@example.test', 'password' => 'baru12345', 'password_confirmation' => 'baru12345'])->assertOk();

        $this->assertTrue(Hash::check('baru12345', $user->refresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/auth/login', ['email' => 'reset@example.test', 'password' => 'baru12345'])->assertOk();
        $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => 'reset@example.test', 'password' => 'lagi12345', 'password_confirmation' => 'lagi12345'])
            ->assertStatus(422)->assertJsonPath('code', 'RESET_TOKEN_INVALID');
    }

    public function test_reset_password_rejects_wrong_token_and_weak_input(): void
    {
        $user = User::factory()->create(['email' => 'reset2@example.test', 'status' => 'active', 'password' => 'lama12345']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', ['token' => 'salah', 'email' => 'reset2@example.test', 'password' => 'baru12345', 'password_confirmation' => 'baru12345'])
            ->assertStatus(422)->assertJsonPath('code', 'RESET_TOKEN_INVALID');
        $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => 'reset2@example.test', 'password' => 'baru12345', 'password_confirmation' => 'beda'])->assertStatus(422);
        $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => 'reset2@example.test', 'password' => 'pendek', 'password_confirmation' => 'pendek'])->assertStatus(422);
        $this->assertTrue(Hash::check('lama12345', $user->refresh()->password));
    }

    public function test_platform_admin_password_cannot_be_reset_through_the_client_door(): void
    {
        $admin = User::factory()->create(['email' => 'root@example.test', 'status' => 'active', 'is_platform_admin' => true, 'password' => 'lama12345']);
        $token = Password::broker()->createToken($admin);

        $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => 'root@example.test', 'password' => 'baru12345', 'password_confirmation' => 'baru12345'])
            ->assertStatus(422)->assertJsonPath('code', 'RESET_TOKEN_INVALID');
        $this->assertTrue(Hash::check('lama12345', $admin->refresh()->password));
    }

    /** @param list<string> $permissions */
    private function invitation(Company $company, string $email, array $permissions): CompanyInvitation
    {
        return CompanyInvitation::query()->create([
            'company_id' => $company->id,
            'email' => $email,
            'full_access' => false,
            'permissions' => $permissions,
            'token' => Str::random(48),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);
    }

    private function companyContext(): array
    {
        $this->seed(PermissionSeeder::class);
        $owner = User::factory()->create(['status' => 'active', 'extra_users' => 10]);
        $company = Company::query()->create([
            'name' => 'Undangan Company '.uniqid(),
            'slug' => 'undangan-company-'.uniqid(),
            'code' => 'UND-'.uniqid(),
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

        $path = database_path('tenants/test_invitation_'.$company->id.'_'.uniqid(getmypid().'_', true).'.sqlite');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '');
        $this->registerTenantFile($path);
        TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => basename($path),
            'database_path' => $path,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        Sanctum::actingAs($owner, ['*']);

        return ['company' => $company, 'owner' => $owner, 'headers' => ['X-Company-ID' => (string) $company->id]];
    }
}
