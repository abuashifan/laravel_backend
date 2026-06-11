<?php

namespace Tests\Feature\Sales;

use App\Models\FiscalYear;
use App\Models\CompanyAccountingSetting;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Contact;
use App\Models\Tenant\CustomerDeposit;
use App\Models\Tenant\CustomerDepositAllocation;
use App\Models\Tenant\DeliveryOrderLine;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\StockMovement;
use App\Services\Tenant\TenantConnectionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class SalesInvoiceTest extends SalesTestCase
{
    public function test_create_invoice_directly(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.grand_total', 222);
    }

    public function test_simple_auto_post_without_approval_posts_invoice_on_create(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        CompanyAccountingSetting::query()->where('company_id', $ctx['company']->id)->update([
            'transaction_workflow_mode' => 'simple_auto_post',
            'auto_post_transactions' => true,
            'approval_enabled' => false,
        ]);

        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.grand_total', 222)
            ->json('data');

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'sales_invoice')->where('source_id', $invoice['id'])->count());
        $this->assertNotNull(SalesInvoice::query()->findOrFail($invoice['id'])->posted_at);
    }

    public function test_create_invoice_from_sales_order(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createSalesOrder($ctx);

        $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.sales_order_id', $order['id'])
            ->assertJsonPath('data.source_type', 'sales_order')
            ->assertJsonPath('data.source_number', $order['order_number'])
            ->assertJsonPath('data.lines.0.source_line_type', 'sales_order_line')
            ->assertJsonPath('data.lines.0.source_line_id', $order['lines'][0]['id']);
    }

    public function test_create_invoice_from_delivery_order(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createSalesOrder($ctx);
        $delivery = $this->postJson('/api/sales/delivery-orders/from-sales-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/delivery-orders/'.$delivery['id'].'/deliver', [], $ctx['headers'])->assertStatus(200);

        $this->postJson('/api/sales/invoices/from-delivery-order/'.$delivery['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.delivery_order_id', $delivery['id'])
            ->assertJsonPath('data.source_type', 'delivery_order')
            ->assertJsonPath('data.lines.0.source_line_type', 'delivery_order_line')
            ->assertJsonPath('data.lines.0.source_line_id', $delivery['lines'][0]['id'])
            ->assertJsonPath('data.grand_total', 222);
    }

    public function test_invoice_from_sales_order_uses_remaining_quantity_and_updates_status_when_posted(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $order = $this->createSalesOrder($ctx, [
            'is_taxable' => false,
            'lines' => [['description' => 'Service', 'quantity' => 5, 'unit_price' => 100]],
        ]);
        $first = $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [
            'lines' => [['sales_order_line_id' => $order['lines'][0]['id'], 'quantity' => 2]],
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/invoices/'.$first['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(2.0, (float) SalesOrderLine::query()->findOrFail($order['lines'][0]['id'])->invoiced_quantity);
        $this->assertSame('partially_invoiced', SalesOrder::query()->findOrFail($order['id'])->status);
        $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.lines.0.quantity', 3);
        $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [
            'lines' => [['sales_order_line_id' => $order['lines'][0]['id'], 'quantity' => 4]],
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_invoice_from_delivery_order_uses_delivered_remaining_and_resolves_order_price(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $order = $this->createSalesOrder($ctx, [
            'is_taxable' => false,
            'lines' => [['description' => 'Goods', 'quantity' => 4, 'unit_price' => 125, 'discount_type' => 'fixed_amount', 'discount_value' => 5]],
        ]);
        $delivery = $this->postJson('/api/sales/delivery-orders/from-sales-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/delivery-orders/'.$delivery['id'].'/deliver', [], $ctx['headers'])->assertStatus(200);
        $first = $this->postJson('/api/sales/invoices/from-delivery-order/'.$delivery['id'], [
            'lines' => [['delivery_order_line_id' => $delivery['lines'][0]['id'], 'quantity' => 1]],
        ], $ctx['headers'])->assertStatus(201)->assertJsonPath('data.lines.0.unit_price', 125)->json('data');
        $this->patchJson('/api/sales/invoices/'.$first['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(1.0, (float) DeliveryOrderLine::query()->findOrFail($delivery['lines'][0]['id'])->invoiced_quantity);
        $this->postJson('/api/sales/invoices/from-delivery-order/'.$delivery['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.lines.0.quantity', 3)
            ->assertJsonPath('data.lines.0.unit_price', 125);
    }

    public function test_create_invoice_from_proforma(): void
    {
        $ctx = $this->setUpTenant();
        $proforma = $this->postJson('/api/sales/proformas', $this->proformaPayload(), $ctx['headers'])->assertStatus(201)->json('data');

        $this->postJson('/api/sales/invoices/from-proforma/'.$proforma['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.proforma_invoice_id', $proforma['id'])
            ->assertJsonPath('data.source_type', 'proforma_invoice');
    }

    public function test_copy_sales_order_discount_into_invoice(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createSalesOrder($ctx, [
            'header_discount_type' => 'fixed_amount',
            'header_discount_value' => 25,
        ]);

        $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.header_discount_amount', 25);
    }

    public function test_allow_invoice_discount_final_adjustment_before_post(): void
    {
        $ctx = $this->setUpTenant();
        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'], $this->invoicePayload([
            'header_discount_type' => 'percent',
            'header_discount_value' => 10,
        ]), $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.header_discount_amount', 20);
    }

    public function test_line_and_header_discount_calculations(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/sales/invoices', $this->invoicePayload([
            'lines' => [['description' => 'Line', 'quantity' => 2, 'unit_price' => 100, 'discount_type' => 'percent', 'discount_value' => 10]],
        ]), $ctx['headers'])->assertStatus(201)->assertJsonPath('data.line_discount_total', 20);

        $this->postJson('/api/sales/invoices', $this->invoicePayload([
            'lines' => [['description' => 'Line', 'quantity' => 2, 'unit_price' => 100, 'discount_type' => 'fixed_amount', 'discount_value' => 25]],
        ]), $ctx['headers'])->assertStatus(201)->assertJsonPath('data.line_discount_total', 25);

        $this->postJson('/api/sales/invoices', $this->invoicePayload([
            'header_discount_type' => 'percent',
            'header_discount_value' => 10,
        ]), $ctx['headers'])->assertStatus(201)->assertJsonPath('data.header_discount_amount', 20);

        $this->postJson('/api/sales/invoices', $this->invoicePayload([
            'header_discount_type' => 'fixed_amount',
            'header_discount_value' => 40,
        ]), $ctx['headers'])->assertStatus(201)->assertJsonPath('data.header_discount_amount', 40);
    }

    public function test_invoice_from_so_reads_and_applies_available_down_payment(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createSalesOrder($ctx);
        $this->createPostedDeposit($order['id'], $order['customer_id'], 50);

        $invoice = $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.applied_down_payment_amount', 50)
            ->json('data');

        $this->seedMappings();
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.paid_amount', 50);

        $this->assertSame(1, CustomerDepositAllocation::query()->count());
    }

    public function test_post_invoice_creates_ar_revenue_journal(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertSame(3, DB::connection('tenant')->table('journal_entry_lines')->count());

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/void', ['reason' => 'Posting corrected'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'void');

        $this->assertSame('void', JournalEntry::query()->firstOrFail()->status);
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/void', ['reason' => 'Again'], $ctx['headers'])
            ->assertStatus(422);
    }

    public function test_post_invoice_fails_with_actionable_message_when_receivable_account_is_missing(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedSalesPostingMappings(modernReceivable: false, legacyReceivable: false);
        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ACCOUNT_MAPPING_MISSING')
            ->assertJsonPath('message', 'Akun Piutang Usaha belum diatur. Buka Pengaturan > Pemetaan Akun > Sales > Piutang Usaha.');
    }

    public function test_post_invoice_uses_transaction_receivable_account_snapshot(): void
    {
        $ctx = $this->setUpTenant();
        $selectedAr = $this->account('1112', 'Piutang Transaksi', 'asset', 'debit');
        $this->seedSalesPostingMappings(modernReceivable: false, legacyReceivable: false);

        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload([
            'ar_account_id' => $selectedAr,
            'is_taxable' => false,
        ]), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.ar_account_id', $selectedAr)
            ->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.ar_account_id', $selectedAr);

        $this->assertSame($selectedAr, $this->journalLineAccount('sales_invoice', $invoice['id']));
    }

    public function test_create_invoice_rejects_non_asset_receivable_account(): void
    {
        $ctx = $this->setUpTenant();
        $revenueAccount = $this->account('4112', 'Not AR', 'revenue', 'credit');

        $this->postJson('/api/sales/invoices', $this->invoicePayload([
            'ar_account_id' => $revenueAccount,
        ]), $ctx['headers'])->assertStatus(422);
    }

    public function test_post_invoice_ignores_deprecated_customer_receivable_account(): void
    {
        $ctx = $this->setUpTenant();
        $customerAr = $this->account('1110', 'Piutang Customer Khusus', 'asset', 'debit');
        $nextCustomerAr = $this->account('1111', 'Piutang Customer Baru', 'asset', 'debit');
        $defaultIds = $this->seedSalesPostingMappings();
        $customerId = $this->createCustomer(['receivable_account_id' => $customerAr]);
        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(['customer_id' => $customerId]), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.ar_account_id', $defaultIds['ar']);

        $this->assertSame($defaultIds['ar'], (int) SalesInvoice::query()->findOrFail($invoice['id'])->ar_account_id);
        $this->assertSame($defaultIds['ar'], (int) DB::connection('tenant')->table('journal_entry_lines')->where('debit', '>', 0)->value('account_id'));

        Contact::query()->findOrFail($customerId)->update(['receivable_account_id' => $nextCustomerAr]);
        $this->assertSame($defaultIds['ar'], (int) SalesInvoice::query()->findOrFail($invoice['id'])->ar_account_id);
    }

    public function test_post_invoice_uses_default_and_legacy_receivable_mapping_fallbacks(): void
    {
        $ctx = $this->setUpTenant();
        $defaultIds = $this->seedSalesPostingMappings();
        $defaultInvoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$defaultInvoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.ar_account_id', $defaultIds['ar']);

        $ctx = $this->setUpTenant();
        $legacyIds = $this->seedSalesPostingMappings(modernReceivable: false, legacyReceivable: true);
        $legacyInvoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$legacyInvoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.ar_account_id', $legacyIds['ar']);
    }

    public function test_post_invoice_uses_product_revenue_snapshots_and_groups_revenue_journal(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedSalesPostingMappings();
        $revenueA = $this->account('4110', 'Revenue A', 'revenue', 'credit');
        $revenueB = $this->account('4120', 'Revenue B', 'revenue', 'credit');
        $productA = $this->product('PRD-A', 'Product A', $revenueA);
        $productB = $this->product('PRD-B', 'Product B', $revenueB);

        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload([
            'is_taxable' => false,
            'lines' => [
                ['product_id' => $productA, 'description' => 'A', 'quantity' => 1, 'unit_price' => 100],
                ['product_id' => $productB, 'description' => 'B', 'quantity' => 1, 'unit_price' => 200],
            ],
        ]), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.lines.0.revenue_account_id', $revenueA)
            ->assertJsonPath('data.lines.1.revenue_account_id', $revenueB)
            ->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $revenueLines = DB::connection('tenant')
            ->table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.source_type', 'sales_invoice')
            ->where('journal_entries.source_id', $invoice['id'])
            ->where('journal_entry_lines.description', 'Sales Revenue')
            ->orderBy('journal_entry_lines.account_id')
            ->get(['journal_entry_lines.account_id', 'journal_entry_lines.credit']);

        $this->assertSame([$revenueA, $revenueB], $revenueLines->pluck('account_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([100.0, 200.0], $revenueLines->pluck('credit')->map(fn ($amount) => (float) $amount)->all());
    }

    public function test_post_invoice_fails_with_actionable_message_when_revenue_account_is_missing(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedSalesPostingMappings(revenue: false);
        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(['is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ACCOUNT_MAPPING_MISSING')
            ->assertJsonPath('message', 'Akun Pendapatan Penjualan belum diatur. Buka Pengaturan > Pemetaan Akun > Sales > Pendapatan Penjualan atau atur Akun Penjualan di master data produk.');
    }

    public function test_sales_invoice_clearing_journals_use_invoice_receivable_snapshot(): void
    {
        $ctx = $this->setUpTenant();
        $customerAr = $this->account('1115', 'Piutang Customer Clearing', 'asset', 'debit');
        $cash = $this->account('1010', 'Kas', 'asset', 'debit');
        $defaultIds = $this->seedSalesPostingMappings();
        $customerId = $this->createCustomer(['receivable_account_id' => $customerAr]);
        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(['customer_id' => $customerId]), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $receipt = $this->postJson('/api/sales/receipts', [
            'receipt_date' => '2026-05-21',
            'customer_id' => $customerId,
            'sales_invoice_id' => $invoice['id'],
            'cash_bank_account_id' => $cash,
            'amount' => 10,
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/receipts/'.$receipt['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame($defaultIds['ar'], $this->journalLineAccount('sales_receipt', $receipt['id'], credit: true));

        $return = $this->postJson('/api/sales/returns/from-invoice/'.$invoice['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/returns/'.$return['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame($defaultIds['ar'], $this->journalLineAccount('sales_return', $return['id'], credit: true, description: 'Accounts Receivable'));

        $order = $this->createSalesOrder($ctx, ['customer_id' => $customerId]);
        $this->createPostedDeposit($order['id'], $customerId, 25);
        $invoiceWithDeposit = $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/invoices/'.$invoiceWithDeposit['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame($defaultIds['ar'], (int) DB::connection('tenant')
            ->table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.source_type', 'sales_invoice')
            ->where('journal_entries.source_id', $invoiceWithDeposit['id'])
            ->where('journal_entries.description', 'like', 'Apply customer deposit%')
            ->where('journal_entry_lines.description', 'Accounts Receivable')
            ->where('journal_entry_lines.credit', '>', 0)
            ->value('journal_entry_lines.account_id'));
    }

    public function test_backfill_sales_invoice_account_snapshots_supports_dry_run_and_execute(): void
    {
        $ctx = $this->setUpTenant();
        $ids = $this->seedSalesPostingMappings();
        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(['is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        SalesInvoice::query()->whereKey($invoice['id'])->update(['ar_account_id' => null]);
        DB::connection('tenant')->table('sales_invoice_lines')->where('sales_invoice_id', $invoice['id'])->update(['revenue_account_id' => null]);

        Artisan::call('tenant:backfill-sales-invoice-account-snapshots', ['--company-id' => $ctx['company']->id]);
        app(TenantConnectionManager::class)->connect($ctx['tenant_path']);
        $this->assertNull(SalesInvoice::query()->findOrFail($invoice['id'])->ar_account_id);
        $this->assertNull(DB::connection('tenant')->table('sales_invoice_lines')->where('sales_invoice_id', $invoice['id'])->value('revenue_account_id'));

        Artisan::call('tenant:backfill-sales-invoice-account-snapshots', ['--company-id' => $ctx['company']->id, '--execute' => true]);
        app(TenantConnectionManager::class)->connect($ctx['tenant_path']);
        $this->assertSame($ids['ar'], (int) SalesInvoice::query()->findOrFail($invoice['id'])->ar_account_id);
        $this->assertSame($ids['revenue'], (int) DB::connection('tenant')->table('sales_invoice_lines')->where('sales_invoice_id', $invoice['id'])->value('revenue_account_id'));
    }

    public function test_post_invoice_creates_deposit_allocation_journal_if_dp_applied(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $order = $this->createSalesOrder($ctx);
        $this->createPostedDeposit($order['id'], $order['customer_id'], 222);
        $invoice = $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.balance_due', 0);

        $this->assertSame(2, JournalEntry::query()->count());
        $this->assertSame(1, CustomerDepositAllocation::query()->count());
        $this->assertSame(0.0, (float) CustomerDeposit::query()->first()->remaining_amount);

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/void', ['reason' => 'Reverse deposit allocation'], $ctx['headers'])
            ->assertStatus(200);
        $this->assertSame('void', CustomerDepositAllocation::query()->firstOrFail()->status);
        $this->assertSame(222.0, (float) CustomerDeposit::query()->firstOrFail()->remaining_amount);
        $this->assertSame(0, JournalEntry::query()->where('status', 'posted')->count());
    }

    public function test_invoice_does_not_create_stock_movement_or_cogs_journal(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'sales_invoice')->count());
    }

    public function test_void_invoice(): void
    {
        $ctx = $this->setUpTenant();
        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/void', ['reason' => 'Wrong invoice'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'void');
    }

    public function test_period_lock_blocks_post(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        FiscalYear::query()->create([
            'company_id' => $ctx['company']->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'closed',
            'is_active' => true,
        ]);
        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(422);
    }

    public function test_permission_denied_for_viewer(): void
    {
        $ctx = $this->setUpTenant('viewer');

        $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])
            ->assertStatus(403);
    }

    public function test_tenant_isolation(): void
    {
        $ctxA = $this->setUpTenant();
        $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctxA['headers'])->assertStatus(201);

        $ctxB = $this->setUpTenant();
        $this->assertSame(0, SalesInvoice::query()->count());
        $this->getJson('/api/sales/invoices', $ctxB['headers'])->assertStatus(200)->assertJsonCount(0, 'data');
    }

    private function invoicePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => $this->createCustomer(),
            'invoice_date' => '2026-05-20',
            'due_date' => '2026-05-30',
            'is_taxable' => true,
            'tax_included' => false,
            'lines' => [['description' => 'Service', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 11]],
        ], $overrides);
    }

    private function proformaPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => $this->createCustomer(),
            'proforma_date' => '2026-05-20',
            'lines' => [['description' => 'Service', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 11]],
        ], $overrides);
    }

    private function createSalesOrder(array $ctx, array $overrides = []): array
    {
        return $this->postJson('/api/sales/orders', array_replace_recursive([
            'customer_id' => $this->createCustomer(),
            'order_date' => '2026-05-20',
            'is_taxable' => true,
            'lines' => [['description' => 'Service', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 11]],
        ], $overrides), $ctx['headers'])->assertStatus(201)->json('data');
    }

    private function createPostedDeposit(int $salesOrderId, int $customerId, float $amount): void
    {
        CustomerDeposit::query()->create([
            'deposit_number' => 'CD-TEST-'.$salesOrderId.'-'.$amount,
            'deposit_date' => '2026-05-20',
            'customer_id' => $customerId,
            'sales_order_id' => $salesOrderId,
            'cash_bank_account_id' => 1,
            'amount' => $amount,
            'allocated_amount' => 0,
            'remaining_amount' => $amount,
            'status' => 'posted',
            'posted_at' => now(),
        ]);
    }

    private function seedMappings(): void
    {
        $this->seedSalesPostingMappings();
    }

    private function seedSalesPostingMappings(?int $ar = null, bool $modernReceivable = true, bool $legacyReceivable = false, bool $revenue = true): array
    {
        $ar ??= $this->account('1100', 'Accounts Receivable', 'asset', 'debit');
        $revenueAccount = $revenue ? $this->account('4100', 'Sales Revenue', 'revenue', 'credit') : null;
        $tax = $this->account('2100', 'Output Tax', 'liability', 'credit');
        $deposit = $this->account('2200', 'Customer Deposit', 'liability', 'credit');
        $return = $this->account('4200', 'Sales Return', 'revenue', 'credit');

        $mappings = [
            'sales.tax_output' => $tax,
            'sales.customer_deposit' => $deposit,
            'sales.return' => $return,
        ];
        if ($revenueAccount !== null) {
            $mappings['sales.revenue'] = $revenueAccount;
        }
        if ($modernReceivable) {
            $mappings['sales.accounts_receivable'] = $ar;
        }
        if ($legacyReceivable) {
            $mappings['accounts_receivable'] = $ar;
        }

        foreach ($mappings as $key => $accountId) {
            AccountMapping::query()->create([
                'mapping_key' => $key,
                'module' => 'sales',
                'account_id' => $accountId,
                'is_required' => true,
                'is_active' => true,
            ]);
        }

        return ['ar' => $ar, 'revenue' => $revenueAccount, 'tax' => $tax, 'deposit' => $deposit, 'return' => $return];
    }

    private function account(string $code, string $name, string $type, string $normal): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $normal,
            'is_active' => true,
        ])->id;
    }

    private function product(string $code, string $name, int $salesAccountId): int
    {
        return (int) Product::query()->create([
            'product_code' => $code,
            'product_name' => $name,
            'product_type' => 'goods',
            'is_active' => true,
            'sales_account_id' => $salesAccountId,
        ])->id;
    }

    private function journalLineAccount(string $sourceType, int $sourceId, bool $credit = false, ?string $description = null): int
    {
        $query = DB::connection('tenant')
            ->table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.source_type', $sourceType)
            ->where('journal_entries.source_id', $sourceId);

        if ($description !== null) {
            $query->where('journal_entry_lines.description', $description);
        }

        $query->where($credit ? 'journal_entry_lines.credit' : 'journal_entry_lines.debit', '>', 0);

        return (int) $query->value('journal_entry_lines.account_id');
    }
}
