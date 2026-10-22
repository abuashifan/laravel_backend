<?php

namespace Tests\Feature\Purchase;

use App\Modules\Purchase\Models\VendorBill;
use App\Modules\Purchase\Models\VendorPayment;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyAccountingSetting;
use Illuminate\Support\Facades\DB;

/**
 * Mode ubah transaksi lock/open untuk tagihan pembelian dan pembayaran vendor.
 */
class TransactionEditModePurchaseTest extends PurchaseTestCase
{
    public function test_lock_mode_blocks_posted_bill_but_allows_draft(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings();

        $draft = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(['is_taxable' => false]), $ctx['headers'])->assertCreated()->json('data');
        $this->deleteJson('/api/purchase/bills/'.$draft['id'], [], $ctx['headers'])->assertOk();
        $this->assertNull(VendorBill::withTrashed()->find($draft['id']));

        $bill = $this->postedBill($ctx);
        $this->patchJson('/api/purchase/bills/'.$bill['id'], ['lines' => $this->lines(3)], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_LOCKED');
    }

    public function test_open_mode_edits_posted_bill_and_reposts_stock_and_journal(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings();
        $this->setMode($ctx['company'], 'open');

        $bill = $this->postedBill($ctx);
        $this->assertEquals(2, $this->postedStockIn($bill['id']));

        $updated = $this->patchJson('/api/purchase/bills/'.$bill['id'], ['lines' => $this->lines(5)], $ctx['headers'])
            ->assertOk()
            ->json('data');

        $this->assertSame('posted', $updated['status']);
        $this->assertSame($bill['bill_number'], $updated['bill_number']);
        $this->assertEquals(500, (float) $updated['grand_total']);
        $this->assertEquals(5, $this->postedStockIn($bill['id']), 'Stok masuk lama dibatalkan dan diganti yang baru.');
        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'vendor_bill')->where('source_id', $bill['id'])->where('status', 'posted')->count());
        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'vendor_bill')->where('source_id', $bill['id'])->where('status', 'void')->count());
    }

    public function test_bill_with_payment_is_locked_until_payment_removed(): void
    {
        $ctx = $this->setUpTenant();
        $accounts = $this->seedPurchaseMappings();
        $this->setMode($ctx['company'], 'open');

        $bill = $this->postedBill($ctx);
        $payment = $this->postJson('/api/purchase/payments', $this->paymentPayload($bill, $accounts['cash'], 50), $ctx['headers'])->assertCreated()->json('data');
        $this->patchJson('/api/purchase/payments/'.$payment['id'].'/post', [], $ctx['headers'])->assertOk();

        $blocked = $this->deleteJson('/api/purchase/bills/'.$bill['id'], ['reason' => 'Hapus'], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_HAS_DEPENDENCY');
        $this->assertStringContainsString($payment['payment_number'], (string) $blocked->json('message'));

        // Pembayaran sendiri bisa diedit (posting ulang) dan dihapus.
        $this->patchJson('/api/purchase/payments/'.$payment['id'], $this->paymentPayload($bill, $accounts['cash'], 80), $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');
        $this->assertEquals(80, (float) VendorBill::query()->find($bill['id'])->paid_amount);

        $this->deleteJson('/api/purchase/payments/'.$payment['id'], ['reason' => 'Batal'], $ctx['headers'])->assertOk();
        $this->assertNull(VendorPayment::query()->find($payment['id']));
        $this->assertEquals(0, (float) VendorBill::query()->find($bill['id'])->paid_amount);

        $this->deleteJson('/api/purchase/bills/'.$bill['id'], ['reason' => 'Hapus'], $ctx['headers'])->assertOk();
        $this->assertSame('void', VendorBill::withTrashed()->find($bill['id'])->status);
        $this->assertEquals(0, $this->postedStockIn($bill['id']));
    }

    private function postedBill(array $ctx): array
    {
        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(['is_taxable' => false]), $ctx['headers'])->assertCreated()->json('data');
        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])->assertOk();

        return VendorBill::query()->find($bill['id'])->toArray();
    }

    private function lines(float $quantity): array
    {
        return [[
            'product_id' => $this->defaultStockProductId,
            'description' => 'Default stock item',
            'quantity' => $quantity,
            'unit_id' => $this->defaultUnitId,
            'unit_price' => 100,
            'warehouse_id' => $this->defaultWarehouseId,
        ]];
    }

    private function paymentPayload(array $bill, int $cash, float $amount): array
    {
        return [
            'payment_date' => '2026-05-20',
            'vendor_id' => $bill['vendor_id'],
            'vendor_bill_id' => $bill['id'],
            'cash_bank_account_id' => $cash,
            'amount' => $amount,
        ];
    }

    private function postedStockIn(int $billId): float
    {
        $movementIds = DB::connection('tenant')->table('stock_movements')
            ->where('source_type', 'vendor_bill')->where('source_id', $billId)->where('status', 'posted')
            ->pluck('id');

        return (float) DB::connection('tenant')->table('stock_movement_lines')->whereIn('stock_movement_id', $movementIds)->sum('quantity');
    }

    private function setMode(Company $company, string $mode): void
    {
        CompanyAccountingSetting::query()->where('company_id', $company->id)->update(['transaction_edit_mode' => $mode]);
    }
}
