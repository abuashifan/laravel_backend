<?php

namespace Tests\TenantPgsql;

use App\Modules\Pos\Services\PosReportTableService;
use App\Shared\Auth\TokenAbility;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Plan;
use App\Shared\Models\Subscription;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Uji beban Finlite Kasir (Fase 4c): satu toko, 2.000 transaksi per hari,
 * di Postgres sungguhan -- database yang dipakai production.
 *
 * Tidak dijalankan di `php artisan test` biasa (suite ini butuh Postgres dan
 * baru jalan kalau `TENANT_TEST_PGSQL_URL` disetel). Kenapa Postgres: SQLite
 * tidak mengindeks foreign key dan perencananya berbeda, jadi angka dari sana
 * menyesatkan untuk keputusan indeks.
 *
 *   TENANT_TEST_PGSQL_URL="postgresql://..." \
 *   POS_LOAD_DAYS=30 POS_LOAD_PER_DAY=2000 \
 *   vendor/bin/phpunit --testsuite=TenantPgsql --filter PosLoadTest
 *
 * Variabel: `POS_LOAD_DAYS` (bawaan 30), `POS_LOAD_PER_DAY` (bawaan 2000),
 * `POS_LOAD_BUDGET_MS` (batas per permintaan baca, bawaan 1500),
 * `POS_LOAD_REPORT` (berkas laporan waktu, bawaan storage/logs/pos-load.txt).
 */
class PosLoadTest extends PostgresTenantTestCase
{
    use RefreshDatabase;

    private const CASHIERS = 3;

    /** @var array<string,string> */
    private array $headers = [];

    /** @var list<array{string, float, float}> */
    private array $timings = [];

    public function test_one_store_with_2000_sales_a_day_stays_responsive(): void
    {
        $days = (int) env('POS_LOAD_DAYS', 30);
        $perDay = (int) env('POS_LOAD_PER_DAY', 2000);
        $budget = (float) env('POS_LOAD_BUDGET_MS', 1500);

        $this->activateStore();
        $seedMs = $this->fillBusyStore($days, $perDay);
        $this->line(sprintf('Data: %d hari x %d transaksi = %s transaksi (diisi dalam %.1f detik)', $days, $perDay, number_format($days * $perDay), $seedMs / 1000));

        $today = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        $week = CarbonImmutable::now('Asia/Jakarta')->subDays(6)->toDateString();
        $month = CarbonImmutable::now('Asia/Jakarta')->subDays($days - 1)->toDateString();
        $someSale = DB::connection('pos')->table('pos_sales')->orderByDesc('id')->value('number');
        $someProduct = (int) DB::connection('pos')->table('pos_products')->where('track_stock', true)->value('id');
        $someCustomer = (int) DB::connection('pos')->table('pos_customers')->where('credit_balance', '>', 0)->value('id');

        $reads = [
            'Ringkasan hari ini' => "/api/pos/reports/summary?date_from={$today}&date_to={$today}",
            'Ringkasan 7 hari' => "/api/pos/reports/summary?date_from={$week}&date_to={$today}",
            "Ringkasan {$days} hari" => "/api/pos/reports/summary?date_from={$month}&date_to={$today}",
            'Dasbor pemilik' => '/api/pos/reports/dashboard',
            'Daftar transaksi hari ini (100)' => "/api/pos/sales?date_from={$today}&date_to={$today}&per_page=100",
            'Cari transaksi per nomor' => '/api/pos/sales?search='.urlencode((string) $someSale),
            'Transaksi batal bulan ini' => "/api/pos/sales?status=voided&date_from={$month}&date_to={$today}",
            'Cari produk' => '/api/pos/products?search=produk+1',
            'Daftar produk (kasir)' => '/api/pos/products?per_page=100',
            'Cari pelanggan' => '/api/pos/customers?search=pelanggan+1',
            'Riwayat kasbon pelanggan' => "/api/pos/customers/{$someCustomer}/credit",
            'Mutasi stok satu produk' => "/api/pos/products/{$someProduct}/stock-movements",
            'Daftar shift' => '/api/pos/shifts',
            'Log aktivitas' => "/api/pos/audit-logs?date_from={$month}&date_to={$today}",
        ];
        foreach (PosReportTableService::TYPES as $type) {
            $reads["Laporan {$type} 7 hari"] = "/api/pos/reports/{$type}?date_from={$week}&date_to={$today}";
            $reads["Laporan {$type} {$days} hari"] = "/api/pos/reports/{$type}?date_from={$month}&date_to={$today}";
        }

        $slow = [];
        foreach ($reads as $label => $url) {
            $cold = $this->timed(fn () => $this->getJson($url, $this->headers)->assertOk());
            $warm = $this->timed(fn () => $this->getJson($url, $this->headers)->assertOk());
            $this->timings[] = [$label, $cold, $warm];
            if ($warm > $budget) {
                $slow[] = sprintf('%s: %.0f ms', $label, $warm);
            }
        }

        // Tulis: transaksi baru saat tabel sudah besar.
        // Pemilik sudah punya shift terbuka hari ini dari data isian.
        $write = [];
        $products = DB::connection('pos')->table('pos_products')->where('is_active', true)->pluck('id')->all();
        for ($i = 0; $i < 40; $i++) {
            $write[] = $this->timed(fn () => $this->postJson('/api/pos/sales', [
                'payment_method' => 'cash', 'paid_amount' => 1000000,
                'lines' => [['product_id' => $products[$i % count($products)], 'quantity' => 1], ['product_id' => $products[($i + 7) % count($products)], 'quantity' => 2]],
            ], $this->headers)->assertCreated());
        }
        sort($write);
        $this->timings[] = ['Simpan transaksi (rata-rata 40x)', $write[0], array_sum($write) / count($write)];
        $this->timings[] = ['Simpan transaksi (terlambat, p95)', $write[0], $write[(int) floor(count($write) * 0.95) - 1]];

        $this->report();

        $this->assertSame([], $slow, 'Permintaan baca melewati batas '.$budget.' ms:'.PHP_EOL.implode(PHP_EOL, $slow));
        $this->assertLessThan($budget, array_sum($write) / count($write), 'Simpan transaksi terlalu lambat.');
    }

    /**
     * Pengelompokan per hari dan jam kini dikerjakan SQL, dengan ekspresi yang
     * berbeda untuk Postgres dan SQLite. Tes SQLite tidak menjangkau cabang
     * Postgres, jadi di sini dibuktikan batas hari memakai jam toko (WIB), bukan UTC.
     */
    public function test_postgres_groups_sales_by_the_store_day_and_hour(): void
    {
        $this->activateStore();
        $db = DB::connection('pos');
        $owner = (int) User::query()->latest('id')->value('id');
        $shiftId = $db->table('pos_shifts')->insertGetId(['user_id' => $owner, 'opened_at' => '2026-11-12 00:00:00', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

        // 18:30 UTC = 01:30 WIB hari berikutnya; 16:59 UTC = 23:59 WIB hari yang sama.
        foreach (['2026-11-12 18:30:00' => 'A', '2026-11-12 16:59:00' => 'B', '2026-11-13 02:10:00' => 'C'] as $utc => $suffix) {
            $db->table('pos_sales')->insert([
                'number' => 'PJ-TZ-'.$suffix, 'shift_id' => $shiftId, 'user_id' => $owner, 'sold_at' => $utc, 'subtotal' => 1000, 'total' => 1000,
                'payment_method' => 'cash', 'paid_amount' => 1000, 'status' => 'completed', 'created_at' => $utc, 'updated_at' => $utc,
            ]);
        }

        $byDay = collect($this->getJson('/api/pos/reports/summary?date_from=2026-11-12&date_to=2026-11-14', $this->headers)->assertOk()->json('data.by_day'))
            ->pluck('count', 'date')->all();
        $this->assertSame(['2026-11-12' => 1, '2026-11-13' => 2], $byDay);

        $hours = collect($this->getJson('/api/pos/reports/hours?date_from=2026-11-12&date_to=2026-11-14', $this->headers)->assertOk()->json('data.rows'))
            ->pluck('sales_count', 'hour')->all();
        $this->assertSame(['01.00-01.59' => 1, '09.00-09.59' => 1, '23.00-23.59' => 1], $hours);
    }

    private function activateStore(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'Toko Beban '.$user->id, 'slug' => 'toko-beban-'.$user->id,
            'code' => 'BBN-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT), 'status' => 'active', 'created_by' => $user->id,
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
        $tenant = TenantDatabase::forProduct('pos')->where('company_id', $company->id)->firstOrFail();
        $this->trackSchema((string) $tenant->database_name);
        $this->storage()->connect((string) $tenant->database_name, (string) $tenant->database_path, 'pos');
    }

    /** Mengisi tenant dengan data sebesar toko ramai. @return float milidetik */
    private function fillBusyStore(int $days, int $perDay): float
    {
        $start = microtime(true);
        $db = DB::connection('pos');
        mt_srand(4242);
        $owner = (int) User::query()->latest('id')->value('id');
        $cashiers = [$owner => 'Pemilik', 9001 => 'Kasir Dua', 9002 => 'Kasir Tiga'];
        $cashierIds = array_keys($cashiers);

        $categories = [];
        foreach (['Minuman', 'Makanan', 'Sembako', 'Rokok', 'Lainnya'] as $name) {
            $categories[] = $db->table('pos_categories')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        $products = [];
        for ($i = 1; $i <= 120; $i++) {
            $price = mt_rand(20, 500) * 100;
            $products[$i] = ['id' => $i, 'sku' => 'SKU'.$i, 'barcode' => '899'.str_pad((string) $i, 9, '0', STR_PAD_LEFT), 'name' => 'Produk '.$i,
                'category_id' => $categories[$i % count($categories)], 'unit' => 'pcs', 'price' => $price, 'cost' => (int) ($price * 0.7),
                'track_stock' => $i % 3 !== 0, 'stock' => 5000, 'is_active' => true, 'min_stock' => 20, 'created_at' => now(), 'updated_at' => now()];
        }
        $db->table('pos_products')->insert(array_values($products));
        $customers = [];
        for ($i = 1; $i <= 400; $i++) {
            $customers[] = ['id' => $i, 'name' => 'Pelanggan '.$i, 'phone' => '0812'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'credit_balance' => $i % 9 === 0 ? 150000 : 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        }
        $db->table('pos_customers')->insert($customers);

        $shiftId = 0;
        $saleId = 0;
        $lineId = 0;
        $moveId = 0;
        $returnId = 0;
        $payId = 0;
        $methods = array_merge(array_fill(0, 70, 'cash'), array_fill(0, 15, 'qris'), array_fill(0, 10, 'transfer'), array_fill(0, 5, 'credit'));
        $todayLocal = CarbonImmutable::now('Asia/Jakarta')->startOfDay();

        for ($d = $days - 1; $d >= 0; $d--) {
            $day = $todayLocal->subDays($d);
            $isToday = $d === 0;
            $shifts = [];
            foreach ($cashierIds as $cashierId) {
                $shiftId++;
                $opened = $day->setTime(7, 0)->utc();
                $db->table('pos_shifts')->insert(['id' => $shiftId, 'user_id' => $cashierId, 'user_name' => $cashiers[$cashierId], 'opened_at' => $opened, 'opening_cash' => 200000,
                    'closed_at' => $isToday ? null : $day->setTime(21, 0)->utc(), 'expected_cash' => $isToday ? null : 1500000, 'counted_cash' => $isToday ? null : 1500000, 'cash_difference' => $isToday ? null : 0,
                    'status' => $isToday ? 'open' : 'closed', 'created_at' => $opened, 'updated_at' => $opened]);
                $shifts[] = ['id' => $shiftId, 'user' => $cashierId];
            }

            $sales = [];
            $lines = [];
            $moves = [];
            $returns = [];
            $returnLines = [];
            $payments = [];
            for ($n = 1; $n <= $perDay; $n++) {
                $saleId++;
                $shift = $shifts[$n % count($shifts)];
                $soldAt = $day->setTime(7 + (int) floor(($n / $perDay) * 14), mt_rand(0, 59), mt_rand(0, 59))->utc();
                $method = $methods[mt_rand(0, 99)];
                $customerId = $method === 'credit' || mt_rand(0, 9) === 0 ? mt_rand(1, 400) : null;
                if ($method === 'credit' && $customerId === null) {
                    $customerId = mt_rand(1, 400);
                }
                $subtotal = 0.0;
                $cost = 0.0;
                $count = mt_rand(1, 5);
                $voided = mt_rand(1, 100) === 1;
                for ($l = 0; $l < $count; $l++) {
                    $lineId++;
                    $p = $products[mt_rand(1, 120)];
                    $qty = mt_rand(1, 3);
                    $total = $p['price'] * $qty;
                    $subtotal += $total;
                    $lines[] = ['id' => $lineId, 'sale_id' => $saleId, 'product_id' => $p['id'], 'product_name' => $p['name'], 'sku' => $p['sku'], 'unit' => 'pcs', 'quantity' => $qty,
                        'unit_price' => $p['price'], 'discount_amount' => 0, 'line_total' => $total, 'unit_cost' => $p['cost'], 'created_at' => $soldAt, 'updated_at' => $soldAt];
                    if ($p['track_stock']) {
                        $moveId++;
                        $moves[] = ['id' => $moveId, 'product_id' => $p['id'], 'type' => 'sale', 'quantity' => -$qty, 'stock_after' => 4000, 'reference_type' => 'pos_sale', 'reference_id' => $saleId,
                            'notes' => 'PJ', 'user_id' => $shift['user'], 'created_at' => $soldAt, 'updated_at' => $soldAt];
                    }
                    if (mt_rand(1, 400) === 1 && ! $voided) {
                        $returnId++;
                        $returns[] = ['id' => $returnId, 'number' => 'RT-'.$day->format('Ymd').'-'.str_pad((string) $returnId, 5, '0', STR_PAD_LEFT), 'sale_id' => $saleId, 'shift_id' => $shift['id'], 'user_id' => $shift['user'],
                            'user_name' => $cashiers[$shift['user']], 'customer_id' => $customerId, 'returned_at' => $soldAt->addMinutes(30), 'total' => $p['price'], 'refund_method' => 'cash', 'created_at' => $soldAt, 'updated_at' => $soldAt];
                        $returnLines[] = ['return_id' => $returnId, 'sale_line_id' => $lineId, 'product_id' => $p['id'], 'product_name' => $p['name'], 'quantity' => 1, 'amount' => $p['price'], 'unit_cost' => $p['cost'], 'restock' => true, 'created_at' => $soldAt, 'updated_at' => $soldAt];
                    }
                }
                $sales[] = ['id' => $saleId, 'number' => 'PJ-'.$day->format('Ymd').'-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT), 'shift_id' => $shift['id'], 'user_id' => $shift['user'], 'user_name' => $cashiers[$shift['user']],
                    'customer_id' => $customerId, 'sold_at' => $soldAt, 'subtotal' => $subtotal, 'discount_amount' => 0, 'total' => $subtotal, 'payment_method' => $method,
                    'paid_amount' => $method === 'credit' ? 0 : $subtotal, 'change_amount' => 0, 'credit_amount' => $method === 'credit' ? $subtotal : 0, 'receipt_mode' => 'none',
                    'status' => $voided ? 'voided' : 'completed', 'voided_at' => $voided ? $soldAt : null, 'void_reason' => $voided ? 'Salah input' : null,
                    'tax_amount' => 0, 'tax_inclusive' => false, 'tax_rate' => 0, 'rounding_amount' => 0, 'created_at' => $soldAt, 'updated_at' => $soldAt];
            }
            for ($c = 0; $c < 10; $c++) {
                $payId++;
                $paidAt = $day->setTime(10 + $c, 0)->utc();
                $payments[] = ['id' => $payId, 'number' => 'PK-'.$day->format('Ymd').'-'.$c, 'customer_id' => mt_rand(1, 400), 'shift_id' => $shifts[0]['id'], 'amount' => 50000, 'method' => 'cash', 'paid_at' => $paidAt,
                    'user_id' => $owner, 'user_name' => 'Pemilik', 'created_at' => $paidAt, 'updated_at' => $paidAt];
            }

            foreach ([['pos_sales', $sales], ['pos_sale_lines', $lines], ['pos_stock_movements', $moves], ['pos_sale_returns', $returns], ['pos_sale_return_lines', $returnLines], ['pos_credit_payments', $payments]] as [$table, $rows]) {
                foreach (array_chunk($rows, 800) as $chunk) {
                    $db->table($table)->insert($chunk);
                }
            }
        }

        foreach (['pos_shifts', 'pos_sales', 'pos_sale_lines', 'pos_stock_movements', 'pos_sale_returns', 'pos_credit_payments', 'pos_products', 'pos_customers'] as $table) {
            $db->statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), (SELECT COALESCE(MAX(id), 1) FROM {$table}))");
        }
        // Produksi punya autovacuum; di sini statistik perencana dibuat manual.
        $db->statement('ANALYZE');

        return (microtime(true) - $start) * 1000;
    }

    private function timed(callable $run): float
    {
        $start = microtime(true);
        $run();

        return (microtime(true) - $start) * 1000;
    }

    private function report(): void
    {
        $lines = [sprintf('%-46s %10s %10s', 'Permintaan', 'pertama', 'berikutnya')];
        foreach ($this->timings as [$label, $cold, $warm]) {
            $lines[] = sprintf('%-46s %7.0f ms %7.0f ms', $label, $cold, $warm);
        }
        $text = implode(PHP_EOL, $lines).PHP_EOL;
        $path = (string) env('POS_LOAD_REPORT', storage_path('logs/pos-load.txt'));
        @file_put_contents($path, $text, FILE_APPEND);
        fwrite(STDERR, PHP_EOL.$text);
    }

    private function line(string $text): void
    {
        fwrite(STDERR, $text.PHP_EOL);
    }
}
