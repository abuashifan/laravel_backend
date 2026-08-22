<?php

namespace Tests\Feature\Inventory;

use App\Modules\Budget\Models\BudgetLine;
use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Models\BudgetSubmission;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Product;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Models\Warehouse;
use App\Shared\AccountMapping\AccountMappingKey;
use Tests\Feature\Journal\JournalTestCase;

/**
 * Gap B — Stock Movement disambungkan ke `BudgetWarningService`, HANYA untuk
 * `sales_out` (dampak COGS). Lihat `StockMovementController::collectStockMovementBudgetWarnings()`
 * untuk alasan cakupan dibatasi ke satu jenis movement ini.
 */
class StockMovementBudgetWarningTest extends JournalTestCase
{
    private ChartOfAccount $cogs;

    private Department $department;

    private Product $product;

    private Warehouse $warehouse;

    private array $ctx;

    private function bootInventory(): void
    {
        $this->ctx = $this->setUpTenant(role: 'warehouse');

        $inventory = ChartOfAccount::query()->create(['account_code' => '1400', 'account_name' => 'Inventory', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true]);
        $this->cogs = ChartOfAccount::query()->create(['account_code' => '5100', 'account_name' => 'COGS', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true]);
        $equity = ChartOfAccount::query()->create(['account_code' => '3000', 'account_name' => 'Equity', 'account_type' => 'equity', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true]);

        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_ASSET, 'module' => 'inventory', 'account_id' => $inventory->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_COGS, 'module' => 'inventory', 'account_id' => $this->cogs->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::OPENING_BALANCE_EQUITY, 'module' => 'opening_balance', 'account_id' => $equity->id, 'is_active' => true]);

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $this->warehouse = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $this->product = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);
        $this->department = Department::query()->create(['code' => 'OPS', 'name' => 'Operational', 'is_active' => true]);

        // Isi stok lebih dulu — sales_out tidak bisa mengeluarkan stok yang tidak ada.
        $opening = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-01',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'unit_id' => $unit->id, 'quantity' => 100, 'unit_cost' => 50],
            ],
        ], $this->ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/inventory/stock-movements/'.$opening['id'].'/post', [], $this->ctx['headers'])->assertStatus(200);
    }

    /**
     * @param  int|null  $departmentId  null = pagu tingkat perusahaan.
     */
    private function budgetLineFor(float $amount, ?int $departmentId): void
    {
        $period = BudgetPeriod::query()->create([
            'company_id' => $this->ctx['company']->id,
            'name' => 'Anggaran 2026',
            'fiscal_year' => 2026,
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'open',
            'created_by' => $this->ctx['user']->id,
        ]);

        $submission = BudgetSubmission::query()->create([
            'company_id' => $this->ctx['company']->id,
            'budget_period_id' => $period->id,
            'department_id' => $departmentId,
            'status' => 'approved',
            'is_active' => true,
            'version_no' => 1,
            'revision_number' => 1,
            'created_by' => $this->ctx['user']->id,
        ]);

        BudgetLine::query()->create([
            'budget_submission_id' => $submission->id,
            'account_id' => $this->cogs->id,
            'department_id' => $departmentId,
            'direction' => 'expense',
            'amount' => $amount,
        ]);
    }

    /**
     * Pagu TINGKAT PERUSAHAAN (department_id NULL) — satu-satunya bentuk yang
     * berfungsi hari ini. Lihat dokumentasi di
     * `StockMovementController::collectStockMovementBudgetWarnings()`: pagu
     * COGS ber-departemen tidak bisa dicek karena jurnal COGS tidak membawa
     * `department_id` (G11) sehingga `BudgetActualService::sumFor()` tidak
     * pernah menemukan actual-nya. Pagu tingkat perusahaan tidak memasang
     * filter departemen sama sekali, jadi tidak tersentuh masalah itu.
     */
    public function test_posting_a_sales_out_movement_that_exceeds_a_company_level_cogs_budget_returns_a_warning(): void
    {
        $this->bootInventory();
        // 10 unit @ Rp50 modal = Rp500 COGS; pagu Rp100 — jelas terlampaui.
        $this->budgetLineFor(100, departmentId: null);

        $movement = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-15',
            'movement_type' => 'sales_out',
            'lines' => [
                ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 10, 'department_id' => $this->department->id],
            ],
        ], $this->ctx['headers'])->assertStatus(201)->json('data');

        $res = $this->patchJson('/api/inventory/stock-movements/'.$movement['id'].'/post', [], $this->ctx['headers'])
            ->assertStatus(200);

        $warnings = $res->json('meta.warnings');

        $this->assertCount(1, $warnings);
        $this->assertSame($this->cogs->id, $warnings[0]['account_id']);
        // postHoc: jurnal COGS sudah diposting saat check() berjalan.
        $this->assertEqualsWithDelta(500.0, $warnings[0]['new_total'], 0.01);
        $this->assertSame('over_budget', $warnings[0]['state']);
    }

    /**
     * Dokumentasi keterbatasan G11 sebagai test, bukan hanya komentar — bukti
     * hidup yang akan GAGAL (memaksa perhatian) begitu G11 ditutup untuk
     * `StockMovementJournalService` dan ekspektasinya perlu diperbarui jadi
     * "menghasilkan peringatan". Sampai saat itu, pagu COGS ber-departemen
     * TIDAK BISA mendeteksi over-budget sama sekali — bukan cuma kurang presisi.
     */
    public function test_a_department_scoped_cogs_budget_cannot_detect_overspend_yet_g11(): void
    {
        $this->bootInventory();
        $this->budgetLineFor(100, departmentId: $this->department->id);

        $movement = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-15',
            'movement_type' => 'sales_out',
            'lines' => [
                ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 10, 'department_id' => $this->department->id],
            ],
        ], $this->ctx['headers'])->assertStatus(201)->json('data');

        $res = $this->patchJson('/api/inventory/stock-movements/'.$movement['id'].'/post', [], $this->ctx['headers'])
            ->assertStatus(200);

        $this->assertSame(
            [],
            $res->json('meta.warnings'),
            'Kalau assertion ini gagal, G11 untuk stock movement sudah tertutup — '.
            'perbarui test ini untuk mengharapkan peringatan, dan hapus catatan '.
            'keterbatasan di StockMovementController.',
        );
    }

    public function test_posting_an_opening_stock_movement_never_produces_a_budget_warning(): void
    {
        $this->bootInventory();
        $this->budgetLineFor(1, departmentId: null);

        // opening_stock (dibuat di bootInventory()) sudah posted; movement_type
        // itu bukan sales_out, jadi tidak boleh pernah menghasilkan peringatan
        // sama sekali walau pagunya kecil.
        $movement = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-15',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 5, 'unit_cost' => 50],
            ],
        ], $this->ctx['headers'])->assertStatus(201)->json('data');

        $res = $this->patchJson('/api/inventory/stock-movements/'.$movement['id'].'/post', [], $this->ctx['headers'])
            ->assertStatus(200);

        $this->assertSame([], $res->json('meta.warnings'));
    }
}
