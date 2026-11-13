<?php

namespace Tests\Feature\Pos;

use App\Mail\CompanyInvitationMail;
use App\Modules\Pos\Controllers\PosSignupController;
use App\Modules\Pos\Models\PosAuditLog;
use App\Modules\Pos\Models\PosSupervisorPin;
use App\Shared\Models\CompanyInvitation;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Fase 4b POS: log audit, batas diskon per kasir, PIN kepala toko.
 */
class PosControlsTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDirectory = storage_path('framework/testing/pos-controls-'.uniqid(getmypid().'_', true));
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

    /** Pemilik dan kasir bergantian lewat token Bearer. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    public function test_audit_log_records_sensitive_actions_and_is_permission_gated(): void
    {
        [$owner, $cashier, $productId] = $this->store();

        $this->patchJson("/api/pos/products/{$productId}", ['price' => 12000], $owner)->assertOk();
        $this->patchJson("/api/pos/products/{$productId}", ['name' => 'Nama baru saja'], $owner)->assertOk();
        $this->postJson("/api/pos/products/{$productId}/stock", ['set_stock' => 40, 'notes' => 'Hitung ulang'], $owner)->assertOk();

        $shiftId = $this->postJson('/api/pos/shifts', ['opening_cash' => 50000], $owner)->assertCreated()->json('data.shift.id');
        $saleId = $this->sell($owner, $productId)->assertCreated()->json('data.id');
        $this->postJson("/api/pos/sales/{$saleId}/void", ['reason' => 'Salah input'], $owner)->assertOk();
        $this->postJson("/api/pos/shifts/{$shiftId}/close", ['counted_cash' => 50000], $owner)->assertOk();

        $actions = collect($this->getJson('/api/pos/audit-logs?per_page=50', $owner)->assertOk()->json('data.data'));
        $this->assertEqualsCanonicalizing(
            ['shift.open', 'sale.void', 'shift.close', 'product.price_change', 'stock.adjust'],
            $actions->pluck('action')->unique()->values()->all(),
        );
        // Mengganti nama produk saja bukan perubahan harga.
        $this->assertSame(1, $actions->where('action', 'product.price_change')->count());
        $price = $actions->firstWhere('action', 'product.price_change');
        $this->assertEquals([10000, 12000], $price['changes']['price']);
        $this->assertSame('Ubah harga produk', $price['action_label']);
        $void = $actions->firstWhere('action', 'sale.void');
        $this->assertSame('Salah input', $void['reason']);
        $this->assertFalse($void['approved_with_pin']);

        $this->getJson('/api/pos/audit-logs?action=sale.void', $owner)->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/pos/audit-logs?action=bukan.aksi', $owner)->assertStatus(422);
        $this->getJson('/api/pos/audit-logs/actions', $owner)->assertOk()->assertJsonPath('data.0.key', 'shift.open');

        // Kasir tidak punya izin log aktivitas.
        $this->getJson('/api/pos/audit-logs', $cashier)->assertStatus(403);
    }

    public function test_discount_limit_needs_supervisor_pin_and_leaves_a_trail(): void
    {
        [$owner, $cashier, $productId, $cashierMemberId] = $this->store();
        $this->patchJson('/api/pos/settings', ['max_discount_percent' => 10], $owner)->assertOk()->assertJsonPath('data.max_discount_percent', '10');
        $this->putJson('/api/pos/security/pin', ['pin' => '4321'], $owner)->assertOk();
        $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $cashier)->assertCreated();

        // 5% lolos; 20% (diskon nota 2.000 dari 10.000) butuh PIN.
        $this->sell($cashier, $productId, discount: 500)->assertCreated();
        $this->sell($cashier, $productId, discount: 2000)->assertStatus(422)->assertJsonValidationErrors(['supervisor_pin']);
        $this->sell($cashier, $productId, discount: 2000, pin: '0000')->assertStatus(422)->assertJsonValidationErrors(['supervisor_pin']);
        $this->sell($cashier, $productId, discount: 2000, pin: '4321')->assertCreated();

        // Harga yang diturunkan kasir dihitung sebagai diskon juga.
        $this->sell($cashier, $productId, unitPrice: 7000)->assertStatus(422)->assertJsonValidationErrors(['supervisor_pin']);

        $log = PosAuditLog::query()->where('action', 'discount.override')->firstOrFail();
        $this->assertTrue($log->approved_with_pin);
        $this->assertSame(20.0, (float) $log->changes['discount_percent']);
        $this->assertSame(1, PosAuditLog::query()->where('action', 'pin.failed')->count());

        // Batas pribadi mengalahkan batas toko: 5% -> diskon 8% ditolak.
        $this->patchJson("/api/pos/team/members/{$cashierMemberId}", ['max_discount_percent' => 5], $owner)->assertOk();
        $this->sell($cashier, $productId, discount: 800)->assertStatus(422);
        $team = $this->getJson('/api/pos/team', $owner)->assertOk();
        $this->assertEquals(5, collect($team->json('data.members'))->firstWhere('role', 'kasir')['max_discount_percent']);
        // Dikosongkan = kembali ikut batas toko (10%).
        $this->patchJson("/api/pos/team/members/{$cashierMemberId}", ['max_discount_percent' => null], $owner)->assertOk();
        $this->sell($cashier, $productId, discount: 800)->assertCreated();

        // Pemilik tidak dibatasi.
        $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $owner)->assertCreated();
        $this->sell($owner, $productId, discount: 5000)->assertCreated();
    }

    public function test_pin_locks_after_repeated_failures_and_never_leaks(): void
    {
        [$owner, $cashier, $productId] = $this->store();
        $this->patchJson('/api/pos/settings', ['max_discount_percent' => 0], $owner)->assertOk();
        $this->putJson('/api/pos/security/pin', ['pin' => '1357'], $owner)->assertOk();
        $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $cashier)->assertCreated();

        $settings = $this->getJson('/api/pos/settings', $cashier)->assertOk();
        $this->assertSame('1', $settings->json('data.supervisor_pin_set'));
        $this->assertStringNotContainsString('pin_hash', $settings->getContent());
        $this->assertStringNotContainsString(PosSupervisorPin::query()->value('pin_hash'), $settings->getContent());

        foreach (range(1, 5) as $_) {
            $this->sell($cashier, $productId, discount: 1000, pin: '9999')->assertStatus(422);
        }
        // Sudah terkunci: PIN yang benar pun ditolak sampai masa kunci habis.
        $locked = $this->sell($cashier, $productId, discount: 1000, pin: '1357')->assertStatus(422);
        $this->assertStringContainsString('terkunci', $locked->json('errors.supervisor_pin.0'));

        $this->travel(6)->minutes();
        $this->sell($cashier, $productId, discount: 1000, pin: '1357')->assertCreated();
    }

    public function test_cashier_can_void_only_when_allowed_and_with_pin(): void
    {
        [$owner, $cashier, $productId] = $this->store();
        $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $cashier)->assertCreated();
        $saleId = $this->sell($cashier, $productId)->assertCreated()->json('data.id');
        $returnLines = $this->getJson("/api/pos/sales/{$saleId}", $cashier)->json('data.lines');

        // Bawaan: kasir tidak boleh membatalkan maupun meretur.
        $this->postJson("/api/pos/sales/{$saleId}/void", ['reason' => 'x'], $cashier)->assertStatus(403);
        $this->postJson("/api/pos/sales/{$saleId}/returns", ['lines' => [['sale_line_id' => $returnLines[0]['id'], 'quantity' => 1]]], $cashier)->assertStatus(403);

        // Mengizinkan kasir pakai PIN butuh PIN yang sudah dibuat.
        $this->patchJson('/api/pos/settings', ['cashier_void_with_pin' => true], $owner)->assertStatus(422);
        $this->putJson('/api/pos/security/pin', ['pin' => '2468'], $owner)->assertOk();
        $this->patchJson('/api/pos/settings', ['cashier_void_with_pin' => true], $owner)->assertOk()->assertJsonPath('data.cashier_void_with_pin', '1');

        $this->postJson("/api/pos/sales/{$saleId}/void", ['reason' => 'Pelanggan batal'], $cashier)->assertStatus(422)->assertJsonValidationErrors(['supervisor_pin']);
        $this->postJson("/api/pos/sales/{$saleId}/void", ['reason' => 'Pelanggan batal', 'supervisor_pin' => '1111'], $cashier)->assertStatus(422);
        $this->postJson("/api/pos/sales/{$saleId}/void", ['reason' => 'Pelanggan batal', 'supervisor_pin' => '2468'], $cashier)
            ->assertOk()->assertJsonPath('data.status', 'voided');

        $void = PosAuditLog::query()->where('action', 'sale.void')->firstOrFail();
        $this->assertTrue($void->approved_with_pin);
        $this->assertSame('Pelanggan batal', $void->reason);

        // Retur dengan PIN.
        $secondId = $this->sell($cashier, $productId)->assertCreated()->json('data.id');
        $line = $this->getJson("/api/pos/sales/{$secondId}", $cashier)->json('data.lines.0.id');
        $payload = ['lines' => [['sale_line_id' => $line, 'quantity' => 1]], 'refund_method' => 'cash'];
        $this->postJson("/api/pos/sales/{$secondId}/returns", $payload, $cashier)->assertStatus(422);
        $this->postJson("/api/pos/sales/{$secondId}/returns", $payload + ['supervisor_pin' => '2468'], $cashier)->assertCreated();
        $this->assertTrue(PosAuditLog::query()->where('action', 'sale.return')->firstOrFail()->approved_with_pin);

        // Menghapus PIN mematikan izin itu sekaligus.
        $this->deleteJson('/api/pos/security/pin', [], $owner)->assertOk()->assertJsonPath('data.supervisor_pin_set', false);
        $this->getJson('/api/pos/settings', $owner)->assertJsonPath('data.cashier_void_with_pin', '0')->assertJsonPath('data.supervisor_pin_set', '0');
        $third = $this->sell($cashier, $productId)->assertCreated()->json('data.id');
        $this->postJson("/api/pos/sales/{$third}/void", ['reason' => 'x', 'supervisor_pin' => '2468'], $cashier)->assertStatus(403);
    }

    public function test_pin_format_and_change_rules(): void
    {
        [$owner] = $this->store();

        $this->putJson('/api/pos/security/pin', ['pin' => '12'], $owner)->assertStatus(422);
        $this->putJson('/api/pos/security/pin', ['pin' => 'abcd'], $owner)->assertStatus(422);
        $this->putJson('/api/pos/security/pin', ['pin' => '1234'], $owner)->assertOk();
        // Pemilik boleh mengganti tanpa PIN lama.
        $this->putJson('/api/pos/security/pin', ['pin' => '5678'], $owner)->assertOk();
        $this->assertSame(2, PosAuditLog::query()->where('action', 'pin.changed')->count());
    }

    /**
     * Toko POS dengan pemilik, satu kasir (templat Kasir), dan satu produk Rp 10.000.
     *
     * @return array{0: array<string,string>, 1: array<string,string>, 2: int, 3: int}
     */
    private function store(): array
    {
        $ownerToken = $this->postJson('/api/pos/register', [
            'name' => 'Pemilik', 'email' => 'pemilik'.uniqid().'@warung.test',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ])->assertCreated()->json('data.token');
        $user = User::query()->latest('id')->firstOrFail();
        $this->postJson('/api/pos/verify-email', ['token' => PosSignupController::verificationToken($user)])->assertOk();

        $auth = ['Authorization' => 'Bearer '.$ownerToken];
        $companyId = (string) $this->postJson('/api/companies', ['name' => 'Toko Uji', 'product' => 'pos'], $auth)->assertCreated()->json('data.id');
        $owner = $auth + ['X-Company-ID' => $companyId];

        $this->postJson('/api/pos/team/invitations', ['email' => 'kasir@warung.test', 'role' => 'kasir'], $owner)->assertCreated();
        Mail::assertSent(CompanyInvitationMail::class);
        $invitation = CompanyInvitation::query()->where('email', 'kasir@warung.test')->firstOrFail();
        $cashierToken = $this->postJson("/api/invitations/{$invitation->token}/register", [
            'name' => 'Kasir Satu', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ])->assertCreated()->json('data.token');
        $cashier = ['Authorization' => 'Bearer '.$cashierToken, 'X-Company-ID' => $companyId];

        $productId = (int) $this->postJson('/api/pos/products', [
            'name' => 'Beras 1 Kg', 'price' => 10000, 'cost' => 8000, 'track_stock' => true, 'initial_stock' => 100,
        ], $owner)->assertCreated()->json('data.id');

        $cashierMemberId = (int) CompanyUser::query()->where('company_id', $companyId)->where('is_owner', false)->value('id');

        return [$owner, $cashier, $productId, $cashierMemberId];
    }

    /** @param  array<string,string>  $headers */
    private function sell(array $headers, int $productId, float $discount = 0, ?string $pin = null, ?float $unitPrice = null)
    {
        $line = ['product_id' => $productId, 'quantity' => 1];
        if ($unitPrice !== null) {
            $line['unit_price'] = $unitPrice;
        }

        return $this->postJson('/api/pos/sales', array_filter([
            'payment_method' => 'cash',
            'paid_amount' => 100000,
            'discount_amount' => $discount ?: null,
            'supervisor_pin' => $pin,
            'lines' => [$line],
        ], fn ($value) => $value !== null), $headers);
    }
}
