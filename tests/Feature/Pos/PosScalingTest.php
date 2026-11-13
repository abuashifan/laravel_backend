<?php

namespace Tests\Feature\Pos;

use App\Shared\Auth\TokenAbility;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 4c POS: penjaga ketahanan beban yang bisa dibuktikan tanpa Postgres --
 * penomoran dokumen, jumlah query laporan, dan batas hari menurut zona toko.
 * Ukuran waktu sungguhan ada di tests/TenantPgsql/PosLoadTest.php.
 */
class PosScalingTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantDirectory = '';

    /** @var array<string,string> */
    private array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDirectory = storage_path('framework/testing/pos-scaling-'.uniqid(getmypid().'_', true));
        File::ensureDirectoryExists($this->tenantDirectory);
        config(['tenant.database_path' => $this->tenantDirectory, 'tenant.driver' => 'sqlite']);

        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'Warung Beban', 'slug' => 'warung-beban', 'code' => 'WRG-000099', 'status' => 'active', 'created_by' => $user->id,
        ]);
        CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner',
            'full_access' => true, 'is_owner' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        Subscription::query()->create([
            'user_id' => $user->id, 'plan_id' => Plan::query()->where('code', Plan::POS_CODE)->value('id'),
            'product' => 'pos', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'billing_cycle' => 'monthly', 'price' => 0,
        ]);
        Sanctum::actingAs($user, [TokenAbility::CLIENT]);
        $this->headers = ['X-Company-ID' => (string) $company->id];
        $this->postJson('/api/pos/activate', [], $this->headers)->assertOk();
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

    public function test_numbers_are_consecutive_and_a_failed_sale_leaves_no_gap(): void
    {
        $productId = $this->product(stock: 2);
        $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $this->headers)->assertCreated();

        $first = $this->sale($productId, 1)->assertCreated()->json('data.number');
        // Stok tidak cukup: transaksi dibatalkan, penghitung nomor ikut dibatalkan.
        $this->sale($productId, 5)->assertStatus(422);
        $second = $this->sale($productId, 1)->assertCreated()->json('data.number');

        $this->assertStringEndsWith('-0001', $first);
        $this->assertStringEndsWith('-0002', $second);
        $this->assertSame(substr($first, 0, -4), substr($second, 0, -4));
    }

    public function test_counter_resumes_from_existing_documents_when_its_row_is_missing(): void
    {
        $productId = $this->product(stock: 10);
        $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $this->headers)->assertCreated();
        $number = $this->sale($productId, 1)->assertCreated()->json('data.number');

        // Dokumen dibuat sebelum penghitung ada: nomor terakhir 0007, tanpa baris penghitung.
        DB::connection('pos')->table('pos_sales')->update(['number' => substr($number, 0, -4).'0007']);
        DB::connection('pos')->table('pos_number_sequences')->delete();

        $next = $this->sale($productId, 1)->assertCreated()->json('data.number');
        $this->assertStringEndsWith('-0008', $next);
    }

    public function test_credit_aging_uses_a_fixed_number_of_queries(): void
    {
        $productId = $this->product(stock: 1000);
        $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $this->headers)->assertCreated();

        $total = 0;
        $count = function (int $add) use ($productId, &$total): int {
            for ($i = 0; $i < $add; $i++) {
                $customerId = $this->postJson('/api/pos/customers', ['name' => 'Pelanggan '.uniqid()], $this->headers)->assertCreated()->json('data.id');
                $this->postJson('/api/pos/sales', [
                    'payment_method' => 'credit', 'paid_amount' => 0, 'customer_id' => $customerId,
                    'lines' => [['product_id' => $productId, 'quantity' => 1]],
                ], $this->headers)->assertCreated();
            }

            $total += $add;
            // DB::listen, bukan getQueryLog(): koneksi tenant dibuat ulang tiap
            // request, jadi log pada objek koneksi lama tidak melihat apa-apa.
            $queries = 0;
            DB::listen(function ($query) use (&$queries) {
                if ($query->connectionName === 'pos') {
                    $queries++;
                }
            });
            $this->getJson('/api/pos/reports/credit-aging', $this->headers)->assertOk()->assertJsonCount($total, 'data.rows');

            return $queries;
        };

        $small = $count(2);
        $large = $count(6);

        // Menambah pelanggan berkasbon tidak boleh menambah query (dulu satu per pelanggan).
        $this->assertGreaterThan(0, $small, 'Penghitung query tidak melihat query POS.');
        $this->assertSame($small, $large, 'Laporan umur kasbon menjalankan query per pelanggan.');
    }

    public function test_reports_group_by_the_store_day_not_the_utc_day(): void
    {
        $productId = $this->product(stock: 100);
        $shiftId = $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $this->headers)->assertCreated()->json('data.shift.id');
        $userId = (int) DB::connection('pos')->table('pos_shifts')->where('id', $shiftId)->value('user_id');

        // 18:30 UTC = 01:30 WIB hari berikutnya; 16:59 UTC = 23:59 WIB hari yang sama.
        foreach (['2026-11-12 18:30:00' => 'A', '2026-11-12 16:59:00' => 'B', '2026-11-13 02:10:00' => 'C'] as $utc => $suffix) {
            DB::connection('pos')->table('pos_sales')->insert([
                'number' => 'PJ-TEST-'.$suffix, 'shift_id' => $shiftId, 'user_id' => $userId, 'sold_at' => $utc, 'subtotal' => 1000, 'total' => 1000,
                'payment_method' => 'cash', 'paid_amount' => 1000, 'status' => 'completed', 'created_at' => $utc, 'updated_at' => $utc,
            ]);
        }

        $summary = $this->getJson('/api/pos/reports/summary?date_from=2026-11-12&date_to=2026-11-14', $this->headers)->assertOk();
        $byDay = collect($summary->json('data.by_day'))->pluck('count', 'date')->all();
        $this->assertSame(['2026-11-12' => 1, '2026-11-13' => 2], $byDay);

        $hours = collect($this->getJson('/api/pos/reports/hours?date_from=2026-11-12&date_to=2026-11-14', $this->headers)->assertOk()->json('data.rows'))
            ->pluck('sales_count', 'hour')->all();
        $this->assertSame(['01.00-01.59' => 1, '09.00-09.59' => 1, '23.00-23.59' => 1], $hours);
        $this->assertTrue($productId > 0);
    }

    private function product(float $stock): int
    {
        return (int) $this->postJson('/api/pos/products', [
            'name' => 'Beras '.uniqid(), 'price' => 1000, 'track_stock' => true, 'initial_stock' => $stock,
        ], $this->headers)->assertCreated()->json('data.id');
    }

    private function sale(int $productId, float $quantity)
    {
        return $this->postJson('/api/pos/sales', [
            'payment_method' => 'cash', 'paid_amount' => 100000,
            'lines' => [['product_id' => $productId, 'quantity' => $quantity]],
        ], $this->headers);
    }
}
