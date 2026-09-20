<?php

namespace Tests\Feature\Auth;

use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pintu login client tidak boleh membocorkan akun mana yang punya hak istimewa.
 *
 * Sebelumnya akun `is_platform_admin` ditolak dengan 403 berbunyi "Akun ini admin
 * aplikasi. Masuk lewat halaman login admin." — sekaligus memberi tahu penyerang
 * bahwa email itu istimewa DAN menunjukkan pintu mana yang harus digedor.
 */
class ClientLoginDoorTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_is_rejected_as_if_the_account_does_not_exist(): void
    {
        $admin = User::factory()->create([
            'status' => 'active',
            'is_platform_admin' => true,
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Akun tidak ditemukan.');
    }

    /**
     * Penolakannya tidak boleh menyebut halaman login admin dalam bentuk apa pun
     * — itu yang membuat pesan lama jadi petunjuk arah bagi penyerang.
     */
    public function test_rejection_never_mentions_the_admin_login_page(): void
    {
        $admin = User::factory()->create([
            'status' => 'active',
            'is_platform_admin' => true,
            'password' => Hash::make('password123'),
        ]);

        $body = $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password123'])
            ->getContent();

        $this->assertStringNotContainsStringIgnoringCase('admin', (string) $body);
    }

    /**
     * Bentuk galatnya sama persis dengan kredensial salah, jadi tidak ada yang
     * bisa membedakan "akun admin" dari "password keliru" dari luar.
     */
    public function test_shape_matches_a_wrong_password_attempt(): void
    {
        $admin = User::factory()->create([
            'status' => 'active',
            'is_platform_admin' => true,
            'password' => Hash::make('password123'),
        ]);
        $client = User::factory()->create([
            'status' => 'active',
            'password' => Hash::make('password123'),
        ]);

        $adminAttempt = $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password123']);
        $wrongPassword = $this->postJson('/api/auth/login', ['email' => $client->email, 'password' => 'salah-sekali']);

        $this->assertSame($wrongPassword->getStatusCode(), $adminAttempt->getStatusCode());
        $this->assertSame(
            array_keys((array) $wrongPassword->json('errors')),
            array_keys((array) $adminAttempt->json('errors')),
        );
    }

    public function test_platform_admin_still_logs_in_through_the_admin_door(): void
    {
        $admin = User::factory()->create([
            'status' => 'active',
            'is_platform_admin' => true,
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('data.user.is_platform_admin', true);
    }
}
