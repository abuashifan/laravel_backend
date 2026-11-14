<?php

namespace Tests\Feature\Pos;

use App\Modules\Accounting\Services\FiscalYearService;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\Pos\Models\PosBridgeConfig;
use App\Modules\Pos\Models\PosBridgeDispatch;
use App\Modules\Pos\Services\PosClock;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use Carbon\CarbonImmutable;
use Tests\Feature\Journal\JournalTestCase;

/**
 * Fase 5 POS: jembatan ke Finlite Accounting. Satu shift tertutup = satu jurnal
 * "Rekap Kasir", idempoten, tidak pernah menggagalkan penutupan shift.
 */
class PosBridgeTest extends JournalTestCase
{
    /** @var array<string,string> */
    private array $headers = [];

    private Company $company;

    /** @var array<string,int> kode akun => id */
    private array $acc = [];

    private int $productId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $ctx = $this->setUpTenant(role: 'owner');
        $this->setUpPosTenant($ctx['company']);
        $this->enforcePlanFeatures(false);
        $this->company = $ctx['company'];
        $this->headers = $ctx['headers'];

        // COA minimal template Perdagangan Umum.
        foreach ([
            ['1100', 'Kas', 'asset'], ['1110', 'Bank', 'asset'], ['1120', 'Piutang Usaha', 'asset'], ['1131', 'Persediaan Barang', 'asset'], ['2100', 'Hutang Usaha', 'liability'],
            ['2120', 'PPN Keluaran', 'liability'], ['4100', 'Pendapatan Penjualan', 'revenue'], ['4110', 'Retur Penjualan', 'revenue'],
            ['4120', 'Diskon Penjualan', 'revenue'], ['5100', 'Harga Pokok Penjualan', 'expense'],
        ] as [$code, $name, $type]) {
            $this->acc[$code] = ChartOfAccount::query()->create([
                'account_code' => $code, 'account_name' => $name, 'account_type' => $type,
                'normal_balance' => in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit',
                'is_cash_bank' => in_array($code, ['1100', '1110'], true), 'is_active' => true, 'is_system_default' => false,
            ])->id;
        }

        $this->productId = (int) $this->postJson('/api/pos/products', [
            'name' => 'Kopi Susu', 'price' => 5000, 'cost' => 3000, 'track_stock' => true, 'initial_stock' => 100,
        ], $this->headers)->assertCreated()->json('data.id');
    }

    /** @return array<string,int> */
    private function map(array $override = []): array
    {
        return array_replace([
            'cash' => $this->acc['1100'], 'bank' => $this->acc['1110'], 'qris' => $this->acc['1110'], 'receivable' => $this->acc['1120'],
            'revenue' => $this->acc['4100'], 'discount' => $this->acc['4120'], 'sales_return' => $this->acc['4110'], 'tax' => $this->acc['2120'],
        ], $override);
    }

    /** Pemetaan lengkap untuk HPP, persediaan, dan hutang pemasok. */
    private function cogsMap(): array
    {
        return $this->map(['cogs' => $this->acc['5100'], 'inventory' => $this->acc['1131'], 'payable' => $this->acc['2100']]);
    }

    /** Hari ini menurut jam toko (WIB), yang dipakai tanggal jurnal; bukan tanggal server (UTC). */
    private function storeToday(int $plusDays = 0): string
    {
        return CarbonImmutable::parse(app(PosClock::class)->today())->addDays($plusDays)->toDateString();
    }

    private function connect(array $extra = [])
    {
        return $this->putJson('/api/pos/bridge', array_merge(['enabled' => true, 'start_date' => $this->storeToday(), 'account_map' => $this->map()], $extra), $this->headers);
    }

    /** Buka shift, jual (tunai, QRIS, transfer ber-diskon, kasbon), retur tunai; belum menutup. */
    private function busyShift(): int
    {
        $shiftId = (int) $this->postJson('/api/pos/shifts', ['opening_cash' => 100000], $this->headers)->assertCreated()->json('data.shift.id');
        $line = fn (int $quantity) => [['product_id' => $this->productId, 'quantity' => $quantity]];

        $cash = $this->postJson('/api/pos/sales', ['payment_method' => 'cash', 'paid_amount' => 20000, 'lines' => $line(2)], $this->headers)->assertCreated();
        $this->postJson('/api/pos/sales', ['payment_method' => 'qris', 'lines' => $line(1)], $this->headers)->assertCreated();
        $this->postJson('/api/pos/sales', ['payment_method' => 'transfer', 'discount_amount' => 1000, 'lines' => $line(3)], $this->headers)->assertCreated();
        $customerId = (int) $this->postJson('/api/pos/customers', ['name' => 'Bu Sari'], $this->headers)->assertCreated()->json('data.id');
        $this->postJson('/api/pos/sales', ['payment_method' => 'credit', 'paid_amount' => 4000, 'customer_id' => $customerId, 'lines' => $line(2)], $this->headers)->assertCreated();

        $saleLineId = $this->getJson('/api/pos/sales/'.$cash->json('data.id'), $this->headers)->json('data.lines.0.id');
        $this->postJson('/api/pos/sales/'.$cash->json('data.id').'/returns', ['refund_method' => 'cash', 'lines' => [['sale_line_id' => $saleLineId, 'quantity' => 1]]], $this->headers)->assertCreated();

        return $shiftId;
    }

    private function closeShift(int $shiftId, float $counted = 100000 + 9000)
    {
        return $this->postJson("/api/pos/shifts/{$shiftId}/close", ['counted_cash' => $counted], $this->headers);
    }

    /** @return array<string,array{debit:float,credit:float}> kode akun => saldo baris */
    private function linesOf(JournalEntry $journal): array
    {
        $codes = array_flip($this->acc);
        $out = [];
        foreach ($journal->lines as $line) {
            $out[$codes[$line->account_id]] = ['debit' => (float) $line->debit, 'credit' => (float) $line->credit];
        }
        ksort($out);

        return $out;
    }

    public function test_pos_only_store_is_not_eligible(): void
    {
        $other = Company::query()->create([
            'name' => 'Toko POS Saja', 'slug' => 'toko-pos-saja', 'code' => 'POS-000777', 'status' => 'active', 'created_by' => $this->company->created_by,
        ]);
        CompanyUser::query()->create(['company_id' => $other->id, 'user_id' => $this->company->created_by, 'role' => 'owner', 'full_access' => true, 'is_owner' => true, 'status' => 'active', 'joined_at' => now()]);
        $this->setUpPosTenant($other);
        $headers = ['X-Company-ID' => (string) $other->id];

        $this->getJson('/api/pos/bootstrap', $headers)->assertOk()->assertJsonPath('data.accounting_active', false);
        $this->getJson('/api/pos/bootstrap', $this->headers)->assertOk()->assertJsonPath('data.accounting_active', true);
        $this->getJson('/api/pos/bridge', $headers)->assertOk()->assertJsonPath('data.eligible', false);
        $this->putJson('/api/pos/bridge', ['enabled' => true, 'account_map' => []], $headers)->assertStatus(422)->assertJsonPath('code', 'BRIDGE_NOT_AVAILABLE');
    }

    public function test_enabling_needs_a_complete_and_sensible_mapping(): void
    {
        $this->getJson('/api/pos/bridge', $this->headers)->assertOk()->assertJsonPath('data.eligible', true)->assertJsonPath('data.enabled', false);

        $choices = $this->getJson('/api/pos/bridge/accounts', $this->headers)->assertOk();
        $this->assertSame($this->acc['4100'], $choices->json('data.suggestions.revenue'));
        $this->assertSame($this->acc['1131'], $choices->json('data.suggestions.inventory'));

        $this->connect(['account_map' => ['cash' => $this->acc['1100']]])->assertStatus(422)->assertJsonPath('code', 'BRIDGE_MAPPING_INCOMPLETE');
        // Pendapatan tidak boleh dipetakan ke akun aset.
        $this->connect(['account_map' => $this->map(['revenue' => $this->acc['1100']])])->assertStatus(422)->assertJsonPath('code', 'BRIDGE_ACCOUNT_INVALID');
        // HPP butuh akun HPP dan persediaan.
        $this->connect(['post_cogs' => true])->assertStatus(422)->assertJsonPath('code', 'BRIDGE_MAPPING_INCOMPLETE');

        $this->connect()->assertOk()->assertJsonPath('data.state.enabled', true);
    }

    public function test_closing_a_shift_sends_one_balanced_rekap_kasir_journal(): void
    {
        $this->connect()->assertOk();
        $shiftId = $this->busyShift();
        $this->closeShift($shiftId)->assertOk()->assertJsonPath('data.shift.status', 'closed');

        $dispatch = PosBridgeDispatch::query()->where('source_id', $shiftId)->firstOrFail();
        $this->assertSame('sent', $dispatch->status, (string) $dispatch->error);

        $journal = JournalEntry::query()->forSource('pos_shift', $shiftId)->with('lines')->firstOrFail();
        $this->assertSame('pos', $journal->source_module);
        $this->assertTrue($journal->is_system_generated);
        $this->assertSame('posted', $journal->status);
        $this->assertSame($journal->journal_number, $dispatch->journal_number);

        // Kas: tunai 10.000 + uang muka kasbon 4.000 - retur tunai 5.000. Bank dan QRIS satu akun: 14.000 + 5.000.
        $this->assertEquals([
            '1100' => ['debit' => 9000.0, 'credit' => 0.0],
            '1110' => ['debit' => 19000.0, 'credit' => 0.0],
            '1120' => ['debit' => 6000.0, 'credit' => 0.0],
            '4100' => ['debit' => 0.0, 'credit' => 40000.0],
            '4110' => ['debit' => 5000.0, 'credit' => 0.0],
            '4120' => ['debit' => 1000.0, 'credit' => 0.0],
        ], $this->linesOf($journal));
        $this->assertEquals($journal->lines->sum('debit'), $journal->lines->sum('credit'));
    }

    public function test_sending_twice_and_reconnecting_never_duplicates_a_journal(): void
    {
        $this->connect()->assertOk();
        $shiftId = $this->busyShift();
        $this->closeShift($shiftId)->assertOk();
        $dispatchId = PosBridgeDispatch::query()->where('source_id', $shiftId)->value('id');

        $this->postJson("/api/pos/bridge/dispatches/{$dispatchId}/retry", [], $this->headers)->assertOk();
        $this->postJson('/api/pos/bridge/sync', [], $this->headers)->assertOk();
        // Putus lalu sambung lagi dengan tanggal mulai yang sama.
        $this->putJson('/api/pos/bridge', ['enabled' => false], $this->headers)->assertOk()->assertJsonPath('data.state.enabled', false);
        $this->connect()->assertOk();

        $this->assertSame(1, JournalEntry::query()->forSource('pos_shift', $shiftId)->count());
        $this->assertSame(1, PosBridgeDispatch::query()->where('source_id', $shiftId)->count());

        // Dispatch hilang tapi jurnalnya ada: kirim ulang mengenali jurnal lama.
        PosBridgeDispatch::query()->delete();
        $this->postJson('/api/pos/bridge/sync', [], $this->headers)->assertOk()->assertJsonPath('data.queued', 1);
        $this->assertSame(1, JournalEntry::query()->forSource('pos_shift', $shiftId)->count());
        $this->assertSame('sent', PosBridgeDispatch::query()->where('source_id', $shiftId)->value('status'));
    }

    public function test_disconnected_bridge_sends_nothing_new_and_start_date_is_respected(): void
    {
        // Shift ditutup sebelum jembatan menyala: baru dikirim setelah disambungkan.
        $early = $this->busyShift();
        $this->closeShift($early)->assertOk();
        $this->assertSame(0, PosBridgeDispatch::query()->count());
        $this->assertSame(0, JournalEntry::query()->forSource('pos_shift', $early)->count());

        $this->connect()->assertOk()->assertJsonPath('data.sync.queued', 1)->assertJsonPath('data.sync.sent', 1);
        $this->assertSame(1, JournalEntry::query()->forSource('pos_shift', $early)->count());

        // Diputus: shift berikutnya tidak dikirim.
        $this->putJson('/api/pos/bridge', ['enabled' => false], $this->headers)->assertOk();
        $shift = $this->busyShift();
        $this->closeShift($shift)->assertOk();
        $this->assertSame(0, PosBridgeDispatch::query()->where('source_id', $shift)->count());
        $this->postJson('/api/pos/bridge/sync', [], $this->headers)->assertOk()->assertJsonPath('data.queued', 0);

        // Tanggal mulai besok: shift hari ini tidak ikut.
        $this->connect(['start_date' => $this->storeToday(1)])->assertOk()->assertJsonPath('data.sync.queued', 0);
        $this->assertSame(0, JournalEntry::query()->forSource('pos_shift', $shift)->count());
    }

    public function test_a_failing_send_never_blocks_closing_and_can_be_retried(): void
    {
        $this->postJson('/api/pos/settings', [], $this->headers);
        $this->patchJson('/api/pos/settings', ['tax_mode' => 'exclusive', 'tax_rate' => 11], $this->headers)->assertOk();
        // Akun pajak sengaja tidak dipetakan.
        $this->connect(['account_map' => $this->map(['tax' => null])])->assertOk();

        $shiftId = (int) $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $this->headers)->assertCreated()->json('data.shift.id');
        $this->postJson('/api/pos/sales', ['payment_method' => 'qris', 'lines' => [['product_id' => $this->productId, 'quantity' => 2]]], $this->headers)->assertCreated()->assertJsonPath('data.tax_amount', 1100);
        $this->closeShift($shiftId, 0)->assertOk()->assertJsonPath('data.shift.status', 'closed');

        $dispatch = PosBridgeDispatch::query()->where('source_id', $shiftId)->firstOrFail();
        $this->assertSame('failed', $dispatch->status);
        $this->assertStringContainsString('Pajak keluaran', (string) $dispatch->error);
        $this->assertSame(0, JournalEntry::query()->forSource('pos_shift', $shiftId)->count());

        $this->connect()->assertOk();
        $this->postJson("/api/pos/bridge/dispatches/{$dispatch->id}/retry", [], $this->headers)->assertOk()->assertJsonPath('data.status', 'sent');

        $journal = JournalEntry::query()->forSource('pos_shift', $shiftId)->with('lines')->firstOrFail();
        $this->assertEquals([
            '1110' => ['debit' => 11100.0, 'credit' => 0.0],
            '2120' => ['debit' => 0.0, 'credit' => 1100.0],
            '4100' => ['debit' => 0.0, 'credit' => 10000.0],
        ], $this->linesOf($journal));
    }

    public function test_locked_period_holds_the_dispatch_until_unlocked(): void
    {
        $this->connect()->assertOk();
        $fiscalYear = app(FiscalYearService::class)->getOrCreateActiveFiscalYear($this->company);
        $fiscalYear->forceFill(['locked_until' => $this->storeToday()])->save();

        $shiftId = $this->busyShift();
        $this->closeShift($shiftId)->assertOk();

        $dispatch = PosBridgeDispatch::query()->where('source_id', $shiftId)->firstOrFail();
        $this->assertSame('held', $dispatch->status);
        $this->assertStringContainsString('terkunci', (string) $dispatch->error);
        $this->assertSame(0, JournalEntry::query()->forSource('pos_shift', $shiftId)->count());
        $this->getJson('/api/pos/bridge', $this->headers)->assertJsonPath('data.counts.held', 1);

        $fiscalYear->forceFill(['locked_until' => null])->save();
        $this->postJson('/api/pos/bridge/sync', [], $this->headers)->assertOk()->assertJsonPath('data.sent', 1);
        $this->assertSame('sent', $dispatch->refresh()->status);
        $this->assertSame(1, JournalEntry::query()->forSource('pos_shift', $shiftId)->count());
    }

    /**
     * Shift ditutup 01.00 WIB: tanggal toko sudah besok, tanggal server (UTC)
     * masih kemarin. Aturan "transaksi masa depan" akuntansi (tanggal server)
     * tidak boleh menahan kiriman -- kalau iya, setiap shift 00.00-07.00 WIB
     * terjebak di status ditahan.
     */
    public function test_late_night_shift_is_not_held_as_a_future_transaction(): void
    {
        $this->connect(['start_date' => '2026-11-01'])->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 18:00:00', 'UTC'));

        $shiftId = $this->busyShift();
        $this->closeShift($shiftId)->assertOk();

        $dispatch = PosBridgeDispatch::query()->where('source_id', $shiftId)->firstOrFail();
        $this->assertSame('sent', $dispatch->status, (string) $dispatch->error);
        $this->assertSame('2026-11-13', $dispatch->entry_date->toDateString());
        $this->assertSame('2026-11-13', JournalEntry::query()->forSource('pos_shift', $shiftId)->firstOrFail()->journal_date->toDateString());
    }

    public function test_inclusive_tax_and_rounding_still_balance(): void
    {
        $this->patchJson('/api/pos/settings', ['tax_mode' => 'inclusive', 'tax_rate' => 11, 'rounding_unit' => 500, 'rounding_mode' => 'nearest'], $this->headers)->assertOk();
        $productId = (int) $this->postJson('/api/pos/products', ['name' => 'Teh Botol', 'price' => 4900, 'cost' => 3000, 'track_stock' => false], $this->headers)->assertCreated()->json('data.id');
        $this->connect()->assertOk();

        $shiftId = (int) $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $this->headers)->assertCreated()->json('data.shift.id');
        $sale = $this->postJson('/api/pos/sales', ['payment_method' => 'qris', 'lines' => [['product_id' => $productId, 'quantity' => 1]]], $this->headers)->assertCreated();
        $total = (float) $sale->json('data.total');
        $tax = (float) $sale->json('data.tax_amount');
        $rounding = (float) $sale->json('data.rounding_amount');
        $this->assertNotEquals(0.0, $tax);
        $this->assertNotEquals(0.0, $rounding);
        $this->closeShift($shiftId, 0)->assertOk();

        $journal = JournalEntry::query()->forSource('pos_shift', $shiftId)->with('lines')->firstOrFail();
        $lines = $this->linesOf($journal);
        $this->assertEquals($total, $lines['1110']['debit']);
        $this->assertEquals($tax, $lines['2120']['credit']);
        // Harga sudah termasuk pajak: pendapatan = harga + pembulatan - pajak.
        $this->assertEquals(4900 + $rounding - $tax, $lines['4100']['credit']);
        $this->assertEquals($journal->lines->sum('debit'), $journal->lines->sum('credit'));
    }

    public function test_stock_in_and_supplier_payments_become_journals_with_cogs(): void
    {
        $this->connect(['post_cogs' => true, 'account_map' => $this->cogsMap()])->assertOk();
        $supplierId = (int) $this->postJson('/api/pos/suppliers', ['name' => 'Toko Abadi'], $this->headers)->assertCreated()->json('data.id');

        // 10 x 3.000 = 30.000; dibayar tunai 10.000, sisa 20.000 jadi hutang.
        $purchase = $this->postJson('/api/pos/purchases', [
            'supplier_id' => $supplierId, 'paid_amount' => 10000, 'payment_method' => 'cash',
            'lines' => [['product_id' => $this->productId, 'quantity' => 10, 'unit_cost' => 3000]],
        ], $this->headers)->assertCreated();
        $purchaseId = (int) $purchase->json('data.id');

        $dispatch = PosBridgeDispatch::query()->where('kind', 'purchase')->where('source_id', $purchaseId)->firstOrFail();
        $this->assertSame('sent', $dispatch->status, (string) $dispatch->error);
        $this->assertStringContainsString('Toko Abadi', $dispatch->source_label);
        $journal = JournalEntry::query()->forSource('pos_purchase', $purchaseId)->with('lines')->firstOrFail();
        $this->assertSame('pos', $journal->source_module);
        $this->assertEquals([
            '1100' => ['debit' => 0.0, 'credit' => 10000.0],
            '1131' => ['debit' => 30000.0, 'credit' => 0.0],
            '2100' => ['debit' => 0.0, 'credit' => 20000.0],
        ], $this->linesOf($journal));

        // Bayar hutang 20.000 lewat transfer.
        $payment = $this->postJson("/api/pos/suppliers/{$supplierId}/payments", ['amount' => 20000, 'method' => 'transfer'], $this->headers)->assertCreated();
        $paymentId = (int) $payment->json('data.payment.id');
        $this->assertSame('sent', PosBridgeDispatch::query()->where('kind', 'payment')->where('source_id', $paymentId)->value('status'));
        $this->assertEquals([
            '1110' => ['debit' => 0.0, 'credit' => 20000.0],
            '2100' => ['debit' => 20000.0, 'credit' => 0.0],
        ], $this->linesOf(JournalEntry::query()->forSource('pos_supplier_payment', $paymentId)->with('lines')->firstOrFail()));

        // Kirim ulang tidak menggandakan.
        $this->postJson('/api/pos/bridge/sync', [], $this->headers)->assertOk();
        $this->assertSame(1, JournalEntry::query()->forSource('pos_purchase', $purchaseId)->count());
        $this->assertSame(1, JournalEntry::query()->forSource('pos_supplier_payment', $paymentId)->count());
    }

    public function test_stock_in_is_only_sent_together_with_cogs_and_catches_up_when_enabled(): void
    {
        $this->connect()->assertOk();
        $purchaseId = (int) $this->postJson('/api/pos/purchases', [
            'paid_amount' => 6000, 'payment_method' => 'cash',
            'lines' => [['product_id' => $this->productId, 'quantity' => 2, 'unit_cost' => 3000]],
        ], $this->headers)->assertCreated()->json('data.id');

        // Tanpa HPP, stok masuk tidak dikirim (persediaan akan naik tanpa pernah turun).
        $this->assertSame(0, PosBridgeDispatch::query()->where('kind', 'purchase')->count());

        // HPP dinyalakan: stok masuk yang sudah ada ikut terkirim.
        $this->connect(['post_cogs' => true, 'account_map' => $this->cogsMap()])->assertOk()->assertJsonPath('data.sync.queued', 1);
        $this->assertSame('sent', PosBridgeDispatch::query()->where('kind', 'purchase')->where('source_id', $purchaseId)->value('status'));
        $this->assertSame(1, JournalEntry::query()->forSource('pos_purchase', $purchaseId)->count());
    }

    public function test_missing_payable_mapping_fails_the_purchase_dispatch_but_not_the_purchase(): void
    {
        $this->connect(['post_cogs' => true, 'account_map' => $this->cogsMap()])->assertOk();
        // Hutang pemasok dilepas dari pemetaan tanpa mematikan HPP: simpan langsung ke konfigurasi.
        $config = PosBridgeConfig::query()->firstOrFail();
        $map = (array) $config->account_map;
        unset($map['payable']);
        $config->forceFill(['account_map' => $map])->save();

        $supplierId = (int) $this->postJson('/api/pos/suppliers', ['name' => 'Toko Abadi'], $this->headers)->assertCreated()->json('data.id');
        $purchaseId = (int) $this->postJson('/api/pos/purchases', [
            'supplier_id' => $supplierId, 'paid_amount' => 0,
            'lines' => [['product_id' => $this->productId, 'quantity' => 1, 'unit_cost' => 3000]],
        ], $this->headers)->assertCreated()->json('data.id');

        $dispatch = PosBridgeDispatch::query()->where('kind', 'purchase')->where('source_id', $purchaseId)->firstOrFail();
        $this->assertSame('failed', $dispatch->status);
        $this->assertStringContainsString('Hutang pemasok', (string) $dispatch->error);
    }

    public function test_cogs_and_an_empty_shift(): void
    {
        $this->connect(['post_cogs' => true, 'account_map' => $this->cogsMap()])->assertOk();

        // Shift tanpa transaksi: tidak membuat jurnal kosong.
        $emptyId = (int) $this->postJson('/api/pos/shifts', ['opening_cash' => 0], $this->headers)->assertCreated()->json('data.shift.id');
        $this->closeShift($emptyId, 0)->assertOk();
        $this->assertSame('skipped', PosBridgeDispatch::query()->where('source_id', $emptyId)->value('status'));
        $this->assertSame(0, JournalEntry::query()->forSource('pos_shift', $emptyId)->count());

        $shiftId = $this->busyShift();
        $this->closeShift($shiftId)->assertOk();
        $journal = JournalEntry::query()->forSource('pos_shift', $shiftId)->with('lines')->firstOrFail();
        $lines = $this->linesOf($journal);

        // Terjual 8 x 3.000, retur 1 kembali ke stok: HPP 21.000.
        $this->assertEquals(['debit' => 21000.0, 'credit' => 0.0], $lines['5100']);
        $this->assertEquals(['debit' => 0.0, 'credit' => 21000.0], $lines['1131']);
        $this->assertEquals($journal->lines->sum('debit'), $journal->lines->sum('credit'));
    }

    public function test_dispatch_list_filters_by_status(): void
    {
        $this->connect()->assertOk();
        $shiftId = $this->busyShift();
        $this->closeShift($shiftId)->assertOk();

        $this->getJson('/api/pos/bridge/dispatches?status=sent', $this->headers)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.kind', 'shift');
        $this->getJson('/api/pos/bridge/dispatches?status=failed', $this->headers)->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/pos/bridge/dispatches?status=bogus', $this->headers)->assertStatus(422);
    }
}
