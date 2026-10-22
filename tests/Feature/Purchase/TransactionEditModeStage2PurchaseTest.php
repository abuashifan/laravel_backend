<?php

namespace Tests\Feature\Purchase;

use App\Modules\Purchase\Models\GoodsReceipt;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyAccountingSetting;
use Illuminate\Support\Facades\DB;

/**
 * Mode lock/open tahap 2 -- penerimaan barang.
 */
class TransactionEditModeStage2PurchaseTest extends PurchaseTestCase
{
    public function test_lock_mode_blocks_received_goods_receipt(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings(interim: true);

        $receipt = $this->receivedReceipt($ctx);
        $this->patchJson('/api/purchase/goods-receipts/'.$receipt['id'], $this->editPayload($receipt['id'], 5), $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_LOCKED');
    }

    public function test_open_mode_edits_received_goods_receipt_and_reposts_stock(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings(interim: true);
        $this->setMode($ctx['company'], 'open');

        $receipt = $this->receivedReceipt($ctx);
        $this->assertEquals(2, $this->postedStockIn($receipt['id']));

        $updated = $this->patchJson('/api/purchase/goods-receipts/'.$receipt['id'], $this->editPayload($receipt['id'], 5), $ctx['headers'])
            ->assertOk()
            ->json('data');

        $this->assertSame('received', $updated['status']);
        $this->assertSame($receipt['receipt_number'], $updated['receipt_number']);
        $this->assertEquals(5, $this->postedStockIn($receipt['id']));

        $this->deleteJson('/api/purchase/goods-receipts/'.$receipt['id'], ['reason' => 'Salah terima'], $ctx['headers'])->assertOk();
        $this->assertNull(GoodsReceipt::query()->find($receipt['id']));
        $this->assertSame('void', GoodsReceipt::withTrashed()->find($receipt['id'])->status);
        $this->assertEquals(0, $this->postedStockIn($receipt['id']));
    }

    public function test_draft_and_cancelled_goods_receipts_are_deleted_permanently(): void
    {
        $ctx = $this->setUpTenant();

        $draft = $this->postJson('/api/purchase/goods-receipts', $this->goodsReceiptPayload(), $ctx['headers'])->assertCreated()->json('data');
        $this->deleteJson('/api/purchase/goods-receipts/'.$draft['id'], [], $ctx['headers'])->assertOk();
        $this->assertNull(GoodsReceipt::withTrashed()->find($draft['id']));

        $cancelled = $this->postJson('/api/purchase/goods-receipts', $this->goodsReceiptPayload(), $ctx['headers'])->assertCreated()->json('data');
        $this->patchJson('/api/purchase/goods-receipts/'.$cancelled['id'].'/cancel', ['reason' => 'Duplikat'], $ctx['headers'])->assertOk();
        $this->deleteJson('/api/purchase/goods-receipts/'.$cancelled['id'], [], $ctx['headers'])->assertOk();
        $this->assertNull(GoodsReceipt::withTrashed()->find($cancelled['id']));
    }

    private function receivedReceipt(array $ctx): array
    {
        // Dari PO (berharga), supaya jurnal persediaan saat diterima bernilai.
        $order = $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload([
            'is_taxable' => false,
            'lines' => [['quantity' => 10]],
        ]), $ctx['headers'])->assertCreated()->json('data');
        $receipt = $this->postJson('/api/purchase/goods-receipts/from-purchase-order/'.$order['id'], [], $ctx['headers'])->assertCreated()->json('data');
        $lines = GoodsReceipt::query()->find($receipt['id'])->lines()->get()
            ->map(fn ($l) => ['product_id' => $l->product_id, 'purchase_order_line_id' => $l->purchase_order_line_id, 'description' => $l->description, 'quantity' => 2, 'unit_id' => $l->unit_id, 'warehouse_id' => $l->warehouse_id])
            ->all();
        $this->patchJson('/api/purchase/goods-receipts/'.$receipt['id'], ['lines' => $lines], $ctx['headers'])->assertOk();
        $this->patchJson('/api/purchase/goods-receipts/'.$receipt['id'].'/receive', [], $ctx['headers'])->assertOk();

        return GoodsReceipt::query()->find($receipt['id'])->toArray();
    }

    private function editPayload(int $receiptId, float $quantity): array
    {
        return ['lines' => GoodsReceipt::query()->find($receiptId)->lines()->get()
            ->map(fn ($l) => ['product_id' => $l->product_id, 'purchase_order_line_id' => $l->purchase_order_line_id, 'description' => $l->description, 'quantity' => $quantity, 'unit_id' => $l->unit_id, 'warehouse_id' => $l->warehouse_id])
            ->all()];
    }

    private function postedStockIn(int $receiptId): float
    {
        $ids = DB::connection('tenant')->table('stock_movements')
            ->where('source_type', 'goods_receipt')->where('source_id', $receiptId)->where('status', 'posted')
            ->pluck('id');

        return (float) DB::connection('tenant')->table('stock_movement_lines')->whereIn('stock_movement_id', $ids)->sum('quantity');
    }

    private function setMode(Company $company, string $mode): void
    {
        CompanyAccountingSetting::query()->where('company_id', $company->id)->update(['transaction_edit_mode' => $mode]);
    }
}
