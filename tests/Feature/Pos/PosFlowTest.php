<?php

namespace Tests\Feature\Pos;

use App\Shared\Auth\TokenAbility;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDirectory = storage_path('framework/testing/pos-'.uniqid(getmypid().'_', true));
        File::ensureDirectoryExists($this->tenantDirectory);
        config(['tenant.database_path' => $this->tenantDirectory, 'tenant.driver' => 'sqlite']);
    }

    protected function tearDown(): void
    {
        DB::disconnect('tenant');
        if ($this->tenantDirectory !== '' && File::isDirectory($this->tenantDirectory)) {
            File::deleteDirectory($this->tenantDirectory);
        }

        parent::tearDown();
    }

    public function test_pos_routes_require_activation(): void
    {
        [, $headers] = $this->ownerCompany();

        $this->getJson('/api/pos/status', $headers)->assertOk()->assertJsonPath('data.active', false);
        $this->getJson('/api/pos/bootstrap', $headers)->assertStatus(422);
    }

    public function test_non_owner_cannot_activate(): void
    {
        [$company, $headers] = $this->ownerCompany();
        $staff = User::factory()->create(['status' => 'active']);
        CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $staff->id, 'role' => 'staff',
            'full_access' => false, 'is_owner' => false, 'status' => 'active', 'joined_at' => now(),
        ]);
        Sanctum::actingAs($staff, [TokenAbility::CLIENT]);

        $this->postJson('/api/pos/activate', [], $headers)->assertStatus(403);
    }

    public function test_activation_creates_separate_pos_tenant(): void
    {
        [$company, $headers] = $this->ownerCompany();

        $this->postJson('/api/pos/activate', [], $headers)->assertOk()->assertJsonPath('data.active', true);

        $pos = TenantDatabase::forProduct('pos')->where('company_id', $company->id)->firstOrFail();
        $this->assertStringContainsString('_pos', $pos->database_name);
        // Kueri tenant "biasa" (akuntansi) tidak boleh mengambil tenant POS.
        $this->assertNull(TenantDatabase::query()->where('company_id', $company->id)->first());

        // Aktivasi kedua tidak membuat tenant baru.
        $this->postJson('/api/pos/activate', [], $headers)->assertOk();
        $this->assertSame(1, TenantDatabase::forProduct('pos')->where('company_id', $company->id)->count());

        $this->getJson('/api/pos/bootstrap', $headers)
            ->assertOk()
            ->assertJsonPath('data.company.id', $company->id)
            ->assertJsonPath('data.current_shift', null);
    }

    public function test_full_cashier_flow(): void
    {
        [, $headers] = $this->ownerCompany();
        $this->postJson('/api/pos/activate', [], $headers)->assertOk();

        $productId = $this->postJson('/api/pos/products', [
            'name' => 'Indomie Goreng', 'barcode' => '8998866200301', 'price' => 3500, 'cost' => 2800,
            'track_stock' => true, 'initial_stock' => 10,
        ], $headers)->assertCreated()->assertJsonPath('data.stock', 10)->json('data.id');

        $serviceId = $this->postJson('/api/pos/products', ['name' => 'Fotokopi', 'price' => 500], $headers)
            ->assertCreated()->json('data.id');

        // Pencarian tidak peka huruf besar/kecil dan scan barcode.
        $this->getJson('/api/pos/products?search=indomie', $headers)->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/pos/products?barcode=8998866200301', $headers)->assertOk()->assertJsonPath('data.data.0.id', $productId);

        // Belum buka shift -> tidak bisa menjual.
        $this->postJson('/api/pos/sales', $this->sale($productId, 1, 'cash', 5000), $headers)->assertStatus(422);

        $shiftId = $this->postJson('/api/pos/shifts', ['opening_cash' => 100000], $headers)->assertCreated()->json('data.shift.id');
        $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $headers)->assertStatus(422);

        // Tunai dengan kembalian; produk tanpa stok tidak mengurangi apa pun.
        $sale = $this->postJson('/api/pos/sales', [
            'payment_method' => 'cash', 'paid_amount' => 20000, 'receipt_mode' => 'print',
            'lines' => [['product_id' => $productId, 'quantity' => 3], ['product_id' => $serviceId, 'quantity' => 10]],
        ], $headers)->assertCreated()
            ->assertJsonPath('data.total', 15500)
            ->assertJsonPath('data.change_amount', 4500);
        $this->assertStringStartsWith('PJ-', $sale->json('data.number'));
        $this->getJson("/api/pos/products/{$productId}", $headers)->assertJsonPath('data.stock', 7);

        // Bayar kurang & stok tidak cukup ditolak.
        $this->postJson('/api/pos/sales', $this->sale($productId, 1, 'cash', 1000), $headers)->assertStatus(422);
        $this->postJson('/api/pos/sales', $this->sale($productId, 8, 'qris'), $headers)->assertStatus(422);

        // Kasbon wajib pelanggan; uang muka tunai 2000, sisa jadi kasbon.
        $this->postJson('/api/pos/sales', $this->sale($productId, 2, 'credit'), $headers)->assertStatus(422);
        $customerId = $this->postJson('/api/pos/customers', ['name' => 'Bu Sri'], $headers)->assertCreated()->json('data.id');
        $creditSale = $this->postJson('/api/pos/sales', $this->sale($productId, 2, 'credit', 2000) + ['customer_id' => $customerId], $headers)
            ->assertCreated()->assertJsonPath('data.credit_amount', 5000);
        $this->getJson("/api/pos/customers/{$customerId}", $headers)->assertJsonPath('data.credit_balance', 5000);

        // Pelunasan kasbon tidak boleh melebihi sisa.
        $this->postJson("/api/pos/customers/{$customerId}/payments", ['amount' => 6000, 'method' => 'cash'], $headers)->assertStatus(422);
        $this->postJson("/api/pos/customers/{$customerId}/payments", ['amount' => 3000, 'method' => 'cash'], $headers)->assertCreated();
        $this->getJson("/api/pos/customers/{$customerId}", $headers)->assertJsonPath('data.credit_balance', 2000);

        // Kasbon yang sudah dibayar sebagian tidak bisa dibatalkan.
        $this->postJson('/api/pos/sales/'.$creditSale->json('data.id').'/void', ['reason' => 'salah'], $headers)->assertStatus(422);

        // Batalkan penjualan tunai -> stok kembali.
        $this->postJson('/api/pos/sales/'.$sale->json('data.id').'/void', ['reason' => 'Salah input'], $headers)
            ->assertOk()->assertJsonPath('data.status', 'voided');
        $this->getJson("/api/pos/products/{$productId}", $headers)->assertJsonPath('data.stock', 8);

        // Laci: 100.000 modal + 2.000 uang muka kasbon + 3.000 pelunasan (penjualan tunai sudah batal).
        $this->getJson('/api/pos/shifts/current', $headers)->assertOk()->assertJsonPath('data.summary.expected_cash', 105000);
        $this->postJson("/api/pos/shifts/{$shiftId}/close", ['counted_cash' => 104000], $headers)
            ->assertOk()
            ->assertJsonPath('data.shift.status', 'closed')
            ->assertJsonPath('data.shift.cash_difference', -1000);

        $this->getJson('/api/pos/reports/summary', $headers)
            ->assertOk()
            ->assertJsonPath('data.sales_count', 1)
            ->assertJsonPath('data.sales_total', 7000)
            ->assertJsonPath('data.voided_count', 1)
            ->assertJsonPath('data.outstanding_credit', 2000);
    }

    /** @return array<string,mixed> */
    private function sale(int $productId, float $quantity, string $method, float $paid = 0): array
    {
        return [
            'payment_method' => $method,
            'paid_amount' => $paid,
            'lines' => [['product_id' => $productId, 'quantity' => $quantity]],
        ];
    }

    /** @return array{0: Company, 1: array<string,string>} */
    private function ownerCompany(): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'Warung Uji '.$user->id,
            'slug' => 'warung-uji-'.$user->id,
            'code' => 'WRG-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner',
            'full_access' => true, 'is_owner' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        Sanctum::actingAs($user, [TokenAbility::CLIENT]);

        return [$company, ['X-Company-ID' => (string) $company->id]];
    }
}
