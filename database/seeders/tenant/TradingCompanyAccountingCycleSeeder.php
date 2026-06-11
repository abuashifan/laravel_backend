<?php

namespace Database\Seeders\tenant;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class TradingCompanyAccountingCycleSeeder extends Seeder
{
    private const SEEDED_BY = 'trading_company_accounting_cycle_2025';

    private int $year = 2025;

    /** @var array<int, string> */
    private array $seededTables = [];

    /** @var array<int, string> */
    private array $skippedTables = [];

    /** @var array<string, int> */
    private array $accounts = [];

    /** @var array<string, int> */
    private array $contacts = [];

    /** @var array<string, int> */
    private array $units = [];

    /** @var array<string, int> */
    private array $products = [];

    /** @var array<string, int> */
    private array $warehouses = [];

    /** @var array<string, int> */
    private array $departments = [];

    /** @var array<string, int> */
    private array $projects = [];

    /** @var array<string, int> */
    private array $journals = [];

    /** @var array<string, float> */
    private array $stockQty = [];

    /** @var array<string, float> */
    private array $stockValue = [];

    /** @var array<string, int> */
    private array $lastStockMovement = [];

    public function run(): void
    {
        $this->seed(2025, false);
    }

    /**
     * @return array<string,mixed>
     */
    public function seed(int $year = 2025, bool $resetDemoData = false): array
    {
        if ($year < 2000 || $year > 2100) {
            throw new RuntimeException('Year must be between 2000 and 2100.');
        }

        $this->year = $year;
        $this->seededTables = [];
        $this->skippedTables = [];
        $this->accounts = [];
        $this->contacts = [];
        $this->units = [];
        $this->products = [];
        $this->warehouses = [];
        $this->departments = [];
        $this->projects = [];
        $this->journals = [];
        $this->stockQty = [];
        $this->stockValue = [];
        $this->lastStockMovement = [];

        if (! $resetDemoData && $this->demoDataExists()) {
            throw new RuntimeException('Demo accounting cycle data already exists. Re-run with --reset-demo-data to replace it.');
        }

        DB::connection('tenant')->transaction(function () use ($resetDemoData): void {
            if ($resetDemoData) {
                $this->resetDemoData();
            }

            $this->seedMasterData();
            $documents = $this->seedWorkflowDocuments();
            $this->seedJournals();
            $this->linkDocumentJournals($documents);
            $this->seedInventoryBalances();
            $this->seedBankReconciliation();
        });

        return $this->summary();
    }

    private function demoDataExists(): bool
    {
        return $this->has('journal_entries')
            && DB::connection('tenant')->table('journal_entries')
                ->where('source_type', self::SEEDED_BY)
                ->exists();
    }

    private function resetDemoData(): void
    {
        foreach ([
            'bank_reconciliation_lines',
            'stock_opname_lines',
            'stock_opnames',
            'stock_adjustment_lines',
            'stock_adjustments',
            'stock_movement_lines',
            'stock_movements',
            'vendor_payment_lines',
            'vendor_payments',
            'vendor_deposit_allocations',
            'vendor_deposits',
            'purchase_return_lines',
            'purchase_returns',
            'vendor_bill_lines',
            'vendor_bills',
            'goods_receipt_lines',
            'goods_receipts',
            'purchase_order_lines',
            'purchase_orders',
            'purchase_request_lines',
            'purchase_requests',
            'sales_return_lines',
            'sales_returns',
            'sales_receipt_lines',
            'sales_receipts',
            'billing_invoice_lines',
            'billing_invoices',
            'customer_deposit_allocations',
            'customer_deposits',
            'sales_invoice_lines',
            'sales_invoices',
            'proforma_invoice_lines',
            'proforma_invoices',
            'delivery_order_lines',
            'delivery_orders',
            'sales_order_lines',
            'sales_orders',
            'sales_quotation_lines',
            'sales_quotations',
            'cash_receipt_lines',
            'cash_receipts',
            'cash_payment_lines',
            'cash_payments',
            'bank_transfers',
            'bank_reconciliations',
            'journal_entry_lines',
            'journal_entries',
            'stock_balances',
            'products',
            'product_categories',
            'projects',
            'departments',
            'warehouses',
            'units',
            'contacts',
            'account_mappings',
            'chart_of_accounts',
        ] as $table) {
            if (! $this->has($table) || ! Schema::connection('tenant')->hasColumn($table, 'metadata')) {
                continue;
            }

            DB::connection('tenant')->table($table)
                ->where(function ($query) {
                    $query->where('metadata', 'like', '%"seeded_by":"'.self::SEEDED_BY.'"%')
                        ->orWhere('metadata', 'like', '%"seeded_by":"tenant_dummy_full_cycle_january_2026"%');
                })
                ->delete();
        }
    }

    private function seedMasterData(): void
    {
        if (! $this->has('chart_of_accounts')) {
            throw new RuntimeException('chart_of_accounts table is required.');
        }

        foreach ($this->chartOfAccounts() as [$code, $name, $type, $normal, $cash]) {
            $this->accounts[$code] = $this->upsert('chart_of_accounts', 'account_code', $code, [
                'account_name' => $name,
                'account_type' => $type,
                'normal_balance' => $normal,
                'is_cash_bank' => $cash,
                'is_active' => true,
                'is_system_default' => false,
                'description' => 'Demo COA PT Nusantara Dagang Sejahtera '.$this->year.'.',
                'metadata' => $this->metadata(),
            ]);
        }

        $this->seedRows('contacts', 'contact_code', [
            ['CUS-NDS-001', ['name' => 'Toko Sumber Rezeki', 'contact_type' => 'customer', 'is_customer' => true, 'phone' => '021-5010-1001', 'email' => 'orders@sumberrezeki.test', 'address' => 'Jakarta']],
            ['CUS-NDS-002', ['name' => 'Toko Maju Jaya', 'contact_type' => 'customer', 'is_customer' => true, 'phone' => '022-5010-1002', 'email' => 'purchasing@majujaya.test', 'address' => 'Bandung']],
            ['CUS-NDS-003', ['name' => 'CV Berkah Abadi', 'contact_type' => 'customer', 'is_customer' => true, 'phone' => '024-5010-1003', 'address' => 'Semarang']],
            ['CUS-NDS-004', ['name' => 'Toko Amanah Mart', 'contact_type' => 'customer', 'is_customer' => true, 'phone' => '031-5010-1004', 'address' => 'Surabaya']],
            ['CUS-NDS-005', ['name' => 'PT Ritel Nusantara', 'contact_type' => 'customer', 'is_customer' => true, 'phone' => '021-5010-1005', 'email' => 'ap@ritel-nusantara.test', 'address' => 'Tangerang']],
            ['SUP-NDS-001', ['name' => 'PT Grosir Utama Indonesia', 'contact_type' => 'supplier', 'is_supplier' => true, 'phone' => '021-7010-2001', 'email' => 'billing@grosirutamai.test']],
            ['SUP-NDS-002', ['name' => 'CV Sinar Distribusi', 'contact_type' => 'supplier', 'is_supplier' => true, 'phone' => '022-7010-2002']],
            ['SUP-NDS-003', ['name' => 'PT Prima Produk Nasional', 'contact_type' => 'supplier', 'is_supplier' => true, 'phone' => '031-7010-2003']],
            ['SUP-NDS-004', ['name' => 'UD Sentosa Supplier', 'contact_type' => 'supplier', 'is_supplier' => true, 'phone' => '0274-7010-2004']],
            ['OTH-NDS-BANK', ['name' => 'Bank Nusantara', 'contact_type' => 'other']],
        ], $this->contacts);

        $this->seedRows('units', 'code', [
            ['pcs', ['name' => 'pcs', 'precision' => 0]],
            ['karton', ['name' => 'karton', 'precision' => 0]],
            ['dus', ['name' => 'dus', 'precision' => 0]],
            ['pack', ['name' => 'pack', 'precision' => 0]],
            ['kg', ['name' => 'kg', 'precision' => 2]],
            ['liter', ['name' => 'liter', 'precision' => 2]],
        ], $this->units);

        $categories = [];
        $this->seedRows('product_categories', 'name', [
            ['Bahan Pokok NDS', ['is_active' => true]],
            ['Kebutuhan Rumah Tangga NDS', ['is_active' => true]],
        ], $categories);

        $this->seedRows('warehouses', 'code', [
            ['WH-NDS-UTM', ['name' => 'Gudang Utama', 'address' => 'Jl. Industri No. 8, Bekasi', 'is_default' => true]],
            ['WH-NDS-RET', ['name' => 'Gudang Retur', 'address' => 'Jl. Industri No. 8, Bekasi', 'is_default' => false]],
        ], $this->warehouses);

        $this->seedRows('departments', 'code', [
            ['OPS', ['name' => 'Operasional']],
            ['SLS', ['name' => 'Penjualan']],
            ['ADM', ['name' => 'Administrasi']],
            ['GDN', ['name' => 'Gudang']],
        ], $this->departments);

        $this->seedRows('projects', 'code', [
            ['NDS-Q1-2025', ['name' => 'Campaign Q1 2025', 'start_date' => $this->date(1, 1), 'end_date' => $this->date(3, 31), 'status' => 'completed']],
            ['NDS-LEBARAN-2025', ['name' => 'Promo Lebaran 2025', 'start_date' => $this->date(3, 1), 'end_date' => $this->date(4, 30), 'status' => 'completed']],
            ['NDS-YEAREND-2025', ['name' => 'Year End Clearance 2025', 'start_date' => $this->date(11, 1), 'end_date' => $this->date(12, 31), 'status' => 'active']],
        ], $this->projects);

        if ($this->has('products')) {
            foreach ($this->productCatalog($categories) as $row) {
                [$code, $name, $unitCode, $purchasePrice, $salesPrice, $categoryId] = $row;
                $this->products[$code] = $this->upsert('products', 'product_code', $code, [
                    'product_name' => $name,
                    'product_type' => 'goods',
                    'product_category_id' => $categoryId,
                    'unit_id' => $this->units[$unitCode] ?? null,
                    'is_stock_item' => true,
                    'is_active' => true,
                    'description' => 'Demo product PT Nusantara Dagang Sejahtera.',
                    'sales_account_id' => $this->accounts['4100'],
                    'purchase_account_id' => $this->accounts['5100'],
                    'inventory_account_id' => $this->accounts['1130'],
                    'cogs_account_id' => $this->accounts['5100'],
                    'metadata' => $this->metadata(['purchase_price' => $purchasePrice, 'sales_price' => $salesPrice]),
                ]);
            }
        }

        if ($this->has('account_mappings')) {
            foreach ($this->accountMappings() as $key => [$module, $accountCode, $required]) {
                $this->upsert('account_mappings', 'mapping_key', $key, [
                    'module' => $module,
                    'account_id' => $this->accounts[$accountCode],
                    'is_required' => $required,
                    'is_active' => true,
                    'metadata' => $this->metadata(),
                ]);
            }
        }
    }

    /**
     * @return array<string, array{table:string,id:int}>
     */
    private function seedWorkflowDocuments(): array
    {
        $refs = [];

        $quotation = $this->salesChain('001', 1, 'CUS-NDS-001', 'PRD-NDS-001', 280, 72000, 900000, 17000000, 5000000, true);
        $refs = array_merge($refs, $quotation);
        $refs = array_merge($refs, $this->directSalesInvoice('002', 2, 'CUS-NDS-002', 'PRD-NDS-002', 210, 38000, 560000, 0, 61000000, 0, false));
        $refs = array_merge($refs, $this->salesChain('003', 3, 'CUS-NDS-005', 'PRD-NDS-006', 85, 98000, 1540000, 43000000, 0, true));
        $refs = array_merge($refs, $this->directSalesInvoice('004', 7, 'CUS-NDS-003', 'PRD-NDS-004', 360, 9500, 14000, 1800000, 18000000, 2000000, false));
        $refs = array_merge($refs, $this->directSalesInvoice('005', 12, 'CUS-NDS-004', 'PRD-NDS-008', 145, 29500, 420000, 0, 0, 26720000, false));

        $refs = array_merge($refs, $this->purchaseChain('001', 1, 'SUP-NDS-001', 'PRD-NDS-001', 500, 60000, 0, 25000000, 0, true));
        $refs = array_merge($refs, $this->directVendorBill('002', 2, 'SUP-NDS-002', 'PRD-NDS-002', 480, 31500, 0, 55000000, 0, false));
        $refs = array_merge($refs, $this->purchaseChain('003', 5, 'SUP-NDS-003', 'PRD-NDS-006', 260, 91000, 6000000, 43000000, 0, true));
        $refs = array_merge($refs, $this->directVendorBill('004', 8, 'SUP-NDS-004', 'PRD-NDS-007', 220, 16000, 0, 22000000, 0, true));
        $refs = array_merge($refs, $this->directVendorBill('005', 12, 'SUP-NDS-001', 'PRD-NDS-008', 160, 29200, 0, 0, 31514600, false));

        return $refs;
    }

    /**
     * @return array<string, array{table:string,id:int}>
     */
    private function salesChain(string $suffix, int $month, string $customerCode, string $productCode, float $qty, float $unitCost, float $unitPrice, float $receiptAmount, float $depositAmount, bool $withReturn): array
    {
        $customerId = $this->contacts[$customerCode];
        $productId = $this->products[$productCode];
        $unitId = $this->productUnitId($productCode);
        $warehouseId = $this->warehouses['WH-NDS-UTM'];
        $deptId = $this->departments['SLS'];
        $projectId = $month <= 3 ? $this->projects['NDS-Q1-2025'] : $this->projects['NDS-LEBARAN-2025'];
        $gross = $qty * $unitPrice;
        $discount = $suffix === '003' ? 1500000 : 0;
        $base = $gross - $discount;
        $tax = round($base * 0.11, 2);
        $total = $base + $tax;
        $invoiceBalance = max(0, $total - $receiptAmount - $depositAmount - ($withReturn ? 2220000 : 0));

        $quote = $this->document('sales_quotations', 'quotation_number', $this->doc('SQ', $suffix), [
            'quotation_date' => $this->date($month, 3),
            'valid_until' => $this->date($month, 20),
            'customer_id' => $customerId,
            'currency_code' => 'IDR',
            'is_taxable' => true,
            'status' => 'converted',
            'subtotal_before_discount' => $gross,
            'header_discount_type' => $discount > 0 ? 'fixed' : null,
            'header_discount_value' => $discount > 0 ? $discount : null,
            'header_discount_amount' => $discount,
            'subtotal_after_discount' => $base,
            'tax_total' => $tax,
            'grand_total' => $total,
            'converted_at' => $this->at($month, 4),
        ], 'sales_quotation_lines', 'sales_quotation_id', $this->itemLine($productCode, $qty, $unitPrice, $gross, $base, $total, $unitId, $warehouseId, $deptId, $projectId, $tax));

        $order = $this->document('sales_orders', 'order_number', $this->doc('SO', $suffix), [
            'order_date' => $this->date($month, 5),
            'customer_id' => $customerId,
            'quotation_id' => $quote,
            'quotation_number' => $this->doc('SQ', $suffix),
            'currency_code' => 'IDR',
            'is_taxable' => true,
            'has_down_payment' => $depositAmount > 0,
            'status' => 'confirmed',
            'subtotal_before_discount' => $gross,
            'header_discount_type' => $discount > 0 ? 'fixed' : null,
            'header_discount_value' => $discount > 0 ? $discount : null,
            'header_discount_amount' => $discount,
            'subtotal_after_discount' => $base,
            'tax_total' => $tax,
            'grand_total' => $total,
            'delivered_amount' => $total,
            'invoiced_amount' => $total,
            'confirmed_at' => $this->at($month, 5),
        ], 'sales_order_lines', 'sales_order_id', $this->itemLine($productCode, $qty, $unitPrice, $gross, $base, $total, $unitId, $warehouseId, $deptId, $projectId, $tax, ['delivered_quantity' => $qty, 'invoiced_quantity' => $qty]));

        $delivery = $this->document('delivery_orders', 'delivery_number', $this->doc('DO', $suffix), [
            'delivery_date' => $this->date($month, 7),
            'customer_id' => $customerId,
            'sales_order_id' => $order,
            'sales_order_number' => $this->doc('SO', $suffix),
            'warehouse_id' => $warehouseId,
            'status' => 'delivered',
            'delivered_at' => $this->at($month, 8),
        ], 'delivery_order_lines', 'delivery_order_id', [
            'product_id' => $productId,
            'product_code' => $productCode,
            'description' => $this->productName($productCode),
            'quantity' => $qty,
            'invoiced_quantity' => $qty,
            'unit_id' => $unitId,
            'warehouse_id' => $warehouseId,
            'department_id' => $deptId,
            'project_id' => $projectId,
        ]);

        $proforma = $this->document('proforma_invoices', 'proforma_number', $this->doc('PF', $suffix), [
            'proforma_date' => $this->date($month, 6),
            'valid_until' => $this->date($month, 20),
            'customer_id' => $customerId,
            'sales_quotation_id' => $quote,
            'sales_order_id' => $order,
            'currency_code' => 'IDR',
            'is_taxable' => true,
            'status' => 'converted',
            'subtotal_before_discount' => $gross,
            'header_discount_type' => $discount > 0 ? 'fixed' : null,
            'header_discount_value' => $discount > 0 ? $discount : null,
            'header_discount_amount' => $discount,
            'subtotal_after_discount' => $base,
            'tax_total' => $tax,
            'grand_total' => $total,
            'converted_at' => $this->at($month, 9),
        ], 'proforma_invoice_lines', 'proforma_invoice_id', $this->itemLine($productCode, $qty, $unitPrice, $gross, $base, $total, $unitId, $warehouseId, $deptId, $projectId, $tax));

        $invoice = $this->document('sales_invoices', 'invoice_number', $this->doc('SI', $suffix), [
            'invoice_date' => $this->date($month, 9),
            'due_date' => $this->date(min(12, $month + 1), 8),
            'customer_id' => $customerId,
            'sales_order_id' => $order,
            'delivery_order_id' => $delivery,
            'proforma_invoice_id' => $proforma,
            'currency_code' => 'IDR',
            'is_taxable' => true,
            'status' => 'posted',
            'subtotal_before_discount' => $gross,
            'header_discount_type' => $discount > 0 ? 'fixed' : null,
            'header_discount_value' => $discount > 0 ? $discount : null,
            'header_discount_amount' => $discount,
            'subtotal_after_discount' => $base,
            'tax_total' => $tax,
            'grand_total' => $total,
            'applied_down_payment_amount' => $depositAmount,
            'paid_amount' => $receiptAmount,
            'returned_amount' => $withReturn ? 2220000 : 0,
            'balance_due' => $invoiceBalance,
            'posted_at' => $this->at($month, 9),
        ], 'sales_invoice_lines', 'sales_invoice_id', $this->itemLine($productCode, $qty, $unitPrice, $gross, $base, $total, $unitId, $warehouseId, $deptId, $projectId, $tax));

        $billing = $this->document('billing_invoices', 'billing_number', $this->doc('BI', $suffix), [
            'billing_date' => $this->date($month, 10),
            'due_date' => $this->date(min(12, $month + 1), 8),
            'customer_id' => $customerId,
            'sales_invoice_id' => $invoice,
            'sales_invoice_number' => $this->doc('SI', $suffix),
            'status' => $invoiceBalance > 0 ? 'partial' : 'paid',
            'billing_amount' => $total,
            'paid_amount' => $receiptAmount + $depositAmount,
            'balance_due' => $invoiceBalance,
            'issued_at' => $this->at($month, 10),
        ], 'billing_invoice_lines', 'billing_invoice_id', [
            'description' => 'Billing for '.$this->doc('SI', $suffix),
            'amount' => $total,
        ]);

        $refs = [
            'sales_invoice_'.$suffix => ['table' => 'sales_invoices', 'id' => $invoice],
            'delivery_'.$suffix => ['table' => 'delivery_orders', 'id' => $delivery],
        ];

        if ($depositAmount > 0) {
            $deposit = $this->document('customer_deposits', 'deposit_number', $this->doc('CD', $suffix), [
                'deposit_date' => $this->date($month, 6),
                'customer_id' => $customerId,
                'sales_order_id' => $order,
                'cash_bank_account_id' => $this->accounts['1110'],
                'amount' => $depositAmount,
                'allocated_amount' => $depositAmount,
                'remaining_amount' => 0,
                'status' => 'posted',
                'posted_at' => $this->at($month, 6),
            ]);
            $this->document('customer_deposit_allocations', 'id', null, [
                'customer_deposit_id' => $deposit,
                'sales_invoice_id' => $invoice,
                'allocation_date' => $this->date($month, 9),
                'allocated_amount' => $depositAmount,
                'status' => 'posted',
            ], null, null, [], ['customer_deposit_id' => $deposit, 'sales_invoice_id' => $invoice]);
            $refs['customer_deposit_'.$suffix] = ['table' => 'customer_deposits', 'id' => $deposit];
        }

        if ($receiptAmount > 0) {
            $receipt = $this->document('sales_receipts', 'receipt_number', $this->doc('SR', $suffix), [
                'receipt_date' => $this->date(min(12, $month + 1), 12),
                'customer_id' => $customerId,
                'sales_invoice_id' => $invoice,
                'billing_invoice_id' => $billing,
                'cash_bank_account_id' => $this->accounts['1110'],
                'amount' => $receiptAmount,
                'status' => 'posted',
                'posted_at' => $this->at(min(12, $month + 1), 12),
            ], 'sales_receipt_lines', 'sales_receipt_id', [
                'sales_invoice_id' => $invoice,
                'billing_invoice_id' => $billing,
                'amount' => $receiptAmount,
                'description' => 'Customer receipt for '.$this->doc('SI', $suffix),
            ]);
            $refs['sales_receipt_'.$suffix] = ['table' => 'sales_receipts', 'id' => $receipt];
        }

        if ($withReturn) {
            $return = $this->document('sales_returns', 'return_number', $this->doc('SRT', $suffix), [
                'return_date' => $this->date($month, 18),
                'customer_id' => $customerId,
                'sales_invoice_id' => $invoice,
                'delivery_order_id' => $delivery,
                'currency_code' => 'IDR',
                'status' => 'posted',
                'subtotal_before_discount' => 2000000,
                'tax_total' => 220000,
                'grand_total' => 2220000,
                'reason' => 'Barang rusak saat diterima customer.',
                'posted_at' => $this->at($month, 18),
            ], 'sales_return_lines', 'sales_return_id', [
                'product_id' => $productId,
                'product_code' => $productCode,
                'description' => 'Sales return '.$this->productName($productCode),
                'quantity' => 22,
                'unit_id' => $unitId,
                'unit_price' => 90909.09,
                'tax_amount' => 220000,
                'line_total' => 2220000,
                'warehouse_id' => $this->warehouses['WH-NDS-RET'],
                'department_id' => $deptId,
                'project_id' => $projectId,
            ]);
            $refs['sales_return_'.$suffix] = ['table' => 'sales_returns', 'id' => $return];
        }

        return $refs;
    }

    /**
     * @return array<string, array{table:string,id:int}>
     */
    private function directSalesInvoice(string $suffix, int $month, string $customerCode, string $productCode, float $qty, float $unitCost, float $unitPrice, float $discount, float $receiptAmount, float $endingBalance, bool $cashSale): array
    {
        $customerId = $this->contacts[$customerCode];
        $productId = $this->products[$productCode];
        $unitId = $this->productUnitId($productCode);
        $warehouseId = $this->warehouses['WH-NDS-UTM'];
        $deptId = $this->departments['SLS'];
        $projectId = $month >= 11 ? $this->projects['NDS-YEAREND-2025'] : $this->projects['NDS-LEBARAN-2025'];
        $gross = $qty * $unitPrice;
        $base = $gross - $discount;
        $tax = round($base * 0.11, 2);
        $total = $base + $tax;
        $paid = $cashSale ? $total : max(0, $total - $endingBalance);

        $invoice = $this->document('sales_invoices', 'invoice_number', $this->doc('SI', $suffix), [
            'invoice_date' => $this->date($month, 11),
            'due_date' => $this->date(min(12, $month + 1), 10),
            'customer_id' => $customerId,
            'currency_code' => 'IDR',
            'is_taxable' => true,
            'status' => 'posted',
            'subtotal_before_discount' => $gross,
            'header_discount_type' => $discount > 0 ? 'fixed' : null,
            'header_discount_value' => $discount > 0 ? $discount : null,
            'header_discount_amount' => $discount,
            'subtotal_after_discount' => $base,
            'tax_total' => $tax,
            'grand_total' => $total,
            'paid_amount' => $paid,
            'balance_due' => $cashSale ? 0 : $endingBalance,
            'posted_at' => $this->at($month, 11),
        ], 'sales_invoice_lines', 'sales_invoice_id', $this->itemLine($productCode, $qty, $unitPrice, $gross, $base, $total, $unitId, $warehouseId, $deptId, $projectId, $tax));

        $refs = ['sales_invoice_'.$suffix => ['table' => 'sales_invoices', 'id' => $invoice]];

        if ($paid > 0) {
            $receipt = $this->document('sales_receipts', 'receipt_number', $this->doc('SR', $suffix), [
                'receipt_date' => $cashSale ? $this->date($month, 11) : $this->date(min(12, $month + 1), 14),
                'customer_id' => $customerId,
                'sales_invoice_id' => $invoice,
                'cash_bank_account_id' => $cashSale ? $this->accounts['1100'] : $this->accounts['1110'],
                'amount' => $paid,
                'status' => 'posted',
                'posted_at' => $cashSale ? $this->at($month, 11) : $this->at(min(12, $month + 1), 14),
            ], 'sales_receipt_lines', 'sales_receipt_id', [
                'sales_invoice_id' => $invoice,
                'amount' => $paid,
                'description' => 'Receipt for '.$this->doc('SI', $suffix),
            ]);
            $refs['sales_receipt_'.$suffix] = ['table' => 'sales_receipts', 'id' => $receipt];
        }

        return $refs;
    }

    /**
     * @return array<string, array{table:string,id:int}>
     */
    private function purchaseChain(string $suffix, int $month, string $vendorCode, string $productCode, float $qty, float $unitPrice, float $depositAmount, float $paymentAmount, float $endingBalance, bool $withReturn): array
    {
        $vendorId = $this->contacts[$vendorCode];
        $productId = $this->products[$productCode];
        $unitId = $this->productUnitId($productCode);
        $warehouseId = $this->warehouses['WH-NDS-UTM'];
        $deptId = $this->departments['GDN'];
        $projectId = $month <= 3 ? $this->projects['NDS-Q1-2025'] : $this->projects['NDS-LEBARAN-2025'];
        $base = $qty * $unitPrice;
        $tax = round($base * 0.11, 2);
        $total = $base + $tax;

        $request = $this->document('purchase_requests', 'request_number', $this->doc('PR', $suffix), [
            'request_date' => $this->date($month, 2),
            'needed_date' => $this->date($month, 6),
            'department_id' => $deptId,
            'project_id' => $projectId,
            'status' => 'converted',
            'estimated_total' => $base,
            'approved_at' => $this->at($month, 2),
            'converted_at' => $this->at($month, 3),
        ], 'purchase_request_lines', 'purchase_request_id', [
            'product_id' => $productId,
            'product_code' => $productCode,
            'description' => $this->productName($productCode),
            'quantity' => $qty,
            'unit_id' => $unitId,
            'estimated_unit_price' => $unitPrice,
            'estimated_line_total' => $base,
            'warehouse_id' => $warehouseId,
            'department_id' => $deptId,
            'project_id' => $projectId,
        ]);

        $order = $this->document('purchase_orders', 'order_number', $this->doc('PO', $suffix), [
            'order_date' => $this->date($month, 3),
            'expected_date' => $this->date($month, 8),
            'vendor_id' => $vendorId,
            'purchase_request_id' => $request,
            'purchase_request_number' => $this->doc('PR', $suffix),
            'currency_code' => 'IDR',
            'is_taxable' => true,
            'has_down_payment' => $depositAmount > 0,
            'status' => 'confirmed',
            'subtotal_before_discount' => $base,
            'subtotal_after_discount' => $base,
            'tax_total' => $tax,
            'grand_total' => $total,
            'received_amount' => $total,
            'billed_amount' => $total,
            'confirmed_at' => $this->at($month, 3),
        ], 'purchase_order_lines', 'purchase_order_id', $this->purchaseLine($productCode, $qty, $unitPrice, $base, $total, $unitId, $warehouseId, $deptId, $projectId, $tax, ['received_quantity' => $qty, 'billed_quantity' => $qty]));

        $goods = $this->document('goods_receipts', 'receipt_number', $this->doc('GR', $suffix), [
            'receipt_date' => $this->date($month, 8),
            'vendor_id' => $vendorId,
            'purchase_order_id' => $order,
            'purchase_order_number' => $this->doc('PO', $suffix),
            'warehouse_id' => $warehouseId,
            'status' => 'received',
            'received_at' => $this->at($month, 8),
        ], 'goods_receipt_lines', 'goods_receipt_id', [
            'product_id' => $productId,
            'product_code' => $productCode,
            'description' => $this->productName($productCode),
            'quantity' => $qty,
            'billed_quantity' => $qty,
            'unit_id' => $unitId,
            'warehouse_id' => $warehouseId,
            'department_id' => $deptId,
            'project_id' => $projectId,
            'expense_account_id' => $this->accounts['1130'],
        ]);

        $billBalance = $endingBalance > 0 ? $endingBalance : max(0, $total - $paymentAmount - $depositAmount - ($withReturn ? 1998000 : 0));
        $bill = $this->document('vendor_bills', 'bill_number', $this->doc('VB', $suffix), [
            'bill_date' => $this->date($month, 9),
            'due_date' => $this->date(min(12, $month + 1), 9),
            'vendor_id' => $vendorId,
            'vendor_invoice_number' => 'INV-'.$this->doc('VB', $suffix),
            'purchase_order_id' => $order,
            'goods_receipt_id' => $goods,
            'currency_code' => 'IDR',
            'is_taxable' => true,
            'status' => 'posted',
            'subtotal_before_discount' => $base,
            'subtotal_after_discount' => $base,
            'tax_total' => $tax,
            'grand_total' => $total,
            'applied_vendor_deposit_amount' => $depositAmount,
            'paid_amount' => $paymentAmount,
            'returned_amount' => $withReturn ? 1998000 : 0,
            'balance_due' => $billBalance,
            'posted_at' => $this->at($month, 9),
        ], 'vendor_bill_lines', 'vendor_bill_id', $this->purchaseLine($productCode, $qty, $unitPrice, $base, $total, $unitId, $warehouseId, $deptId, $projectId, $tax));

        $refs = [
            'vendor_bill_'.$suffix => ['table' => 'vendor_bills', 'id' => $bill],
            'goods_receipt_'.$suffix => ['table' => 'goods_receipts', 'id' => $goods],
        ];

        if ($depositAmount > 0) {
            $deposit = $this->document('vendor_deposits', 'deposit_number', $this->doc('VD', $suffix), [
                'deposit_date' => $this->date($month, 5),
                'vendor_id' => $vendorId,
                'purchase_order_id' => $order,
                'cash_bank_account_id' => $this->accounts['1110'],
                'amount' => $depositAmount,
                'allocated_amount' => $depositAmount,
                'remaining_amount' => 0,
                'status' => 'posted',
                'posted_at' => $this->at($month, 5),
            ]);
            $this->document('vendor_deposit_allocations', 'id', null, [
                'vendor_deposit_id' => $deposit,
                'vendor_bill_id' => $bill,
                'allocation_date' => $this->date($month, 9),
                'allocated_amount' => $depositAmount,
                'status' => 'posted',
            ], null, null, [], ['vendor_deposit_id' => $deposit, 'vendor_bill_id' => $bill]);
            $refs['vendor_deposit_'.$suffix] = ['table' => 'vendor_deposits', 'id' => $deposit];
        }

        if ($paymentAmount > 0) {
            $payment = $this->document('vendor_payments', 'payment_number', $this->doc('VP', $suffix), [
                'payment_date' => $this->date(min(12, $month + 1), 16),
                'vendor_id' => $vendorId,
                'vendor_bill_id' => $bill,
                'cash_bank_account_id' => $this->accounts['1110'],
                'amount' => $paymentAmount,
                'status' => 'posted',
                'posted_at' => $this->at(min(12, $month + 1), 16),
            ], 'vendor_payment_lines', 'vendor_payment_id', [
                'vendor_bill_id' => $bill,
                'amount' => $paymentAmount,
                'description' => 'Vendor payment for '.$this->doc('VB', $suffix),
            ]);
            $refs['vendor_payment_'.$suffix] = ['table' => 'vendor_payments', 'id' => $payment];
        }

        if ($withReturn) {
            $return = $this->document('purchase_returns', 'return_number', $this->doc('PRT', $suffix), [
                'return_date' => $this->date($month, 20),
                'vendor_id' => $vendorId,
                'vendor_bill_id' => $bill,
                'goods_receipt_id' => $goods,
                'currency_code' => 'IDR',
                'status' => 'posted',
                'subtotal_before_discount' => 1800000,
                'tax_total' => 198000,
                'grand_total' => 1998000,
                'reason' => 'Retur barang rusak dari supplier.',
                'posted_at' => $this->at($month, 20),
            ], 'purchase_return_lines', 'purchase_return_id', [
                'product_id' => $productId,
                'product_code' => $productCode,
                'description' => 'Purchase return '.$this->productName($productCode),
                'quantity' => 112.5,
                'unit_id' => $unitId,
                'unit_price' => 16000,
                'tax_amount' => 198000,
                'line_total' => 1998000,
                'warehouse_id' => $warehouseId,
                'department_id' => $deptId,
                'project_id' => $projectId,
                'expense_account_id' => $this->accounts['1130'],
            ]);
            $refs['purchase_return_'.$suffix] = ['table' => 'purchase_returns', 'id' => $return];
        }

        return $refs;
    }

    /**
     * @return array<string, array{table:string,id:int}>
     */
    private function directVendorBill(string $suffix, int $month, string $vendorCode, string $productCode, float $qty, float $unitPrice, float $discount, float $paymentAmount, float $endingBalance, bool $withReturn): array
    {
        $vendorId = $this->contacts[$vendorCode];
        $unitId = $this->productUnitId($productCode);
        $warehouseId = $this->warehouses['WH-NDS-UTM'];
        $deptId = $this->departments['GDN'];
        $projectId = $month >= 11 ? $this->projects['NDS-YEAREND-2025'] : $this->projects['NDS-LEBARAN-2025'];
        $gross = $qty * $unitPrice;
        $base = $gross - $discount;
        $tax = round($base * 0.11, 2);
        $total = $base + $tax;
        $billBalance = $endingBalance > 0 ? $endingBalance : max(0, $total - $paymentAmount - ($withReturn ? 1998000 : 0));

        $bill = $this->document('vendor_bills', 'bill_number', $this->doc('VB', $suffix), [
            'bill_date' => $this->date($month, 10),
            'due_date' => $this->date(min(12, $month + 1), 10),
            'vendor_id' => $vendorId,
            'vendor_invoice_number' => 'INV-'.$this->doc('VB', $suffix),
            'currency_code' => 'IDR',
            'is_taxable' => true,
            'status' => 'posted',
            'subtotal_before_discount' => $gross,
            'header_discount_type' => $discount > 0 ? 'fixed' : null,
            'header_discount_value' => $discount > 0 ? $discount : null,
            'header_discount_amount' => $discount,
            'subtotal_after_discount' => $base,
            'tax_total' => $tax,
            'grand_total' => $total,
            'paid_amount' => $paymentAmount,
            'returned_amount' => $withReturn ? 1998000 : 0,
            'balance_due' => $billBalance,
            'posted_at' => $this->at($month, 10),
        ], 'vendor_bill_lines', 'vendor_bill_id', $this->purchaseLine($productCode, $qty, $unitPrice, $base, $total, $unitId, $warehouseId, $deptId, $projectId, $tax));

        $refs = ['vendor_bill_'.$suffix => ['table' => 'vendor_bills', 'id' => $bill]];

        if ($paymentAmount > 0) {
            $payment = $this->document('vendor_payments', 'payment_number', $this->doc('VP', $suffix), [
                'payment_date' => $this->date(min(12, $month + 1), 18),
                'vendor_id' => $vendorId,
                'vendor_bill_id' => $bill,
                'cash_bank_account_id' => $this->accounts['1110'],
                'amount' => $paymentAmount,
                'status' => 'posted',
                'posted_at' => $this->at(min(12, $month + 1), 18),
            ], 'vendor_payment_lines', 'vendor_payment_id', [
                'vendor_bill_id' => $bill,
                'amount' => $paymentAmount,
                'description' => 'Vendor payment for '.$this->doc('VB', $suffix),
            ]);
            $refs['vendor_payment_'.$suffix] = ['table' => 'vendor_payments', 'id' => $payment];
        }

        if ($withReturn) {
            $return = $this->document('purchase_returns', 'return_number', $this->doc('PRT', $suffix), [
                'return_date' => $this->date($month, 20),
                'vendor_id' => $vendorId,
                'vendor_bill_id' => $bill,
                'currency_code' => 'IDR',
                'status' => 'posted',
                'subtotal_before_discount' => 1800000,
                'tax_total' => 198000,
                'grand_total' => 1998000,
                'reason' => 'Retur barang rusak dari supplier.',
                'posted_at' => $this->at($month, 20),
            ], 'purchase_return_lines', 'purchase_return_id', [
                'product_id' => $this->products[$productCode],
                'product_code' => $productCode,
                'description' => 'Purchase return '.$this->productName($productCode),
                'quantity' => 112.5,
                'unit_id' => $unitId,
                'unit_price' => 16000,
                'tax_amount' => 198000,
                'line_total' => 1998000,
                'warehouse_id' => $warehouseId,
                'department_id' => $deptId,
                'project_id' => $projectId,
                'expense_account_id' => $this->accounts['1130'],
            ]);
            $refs['purchase_return_'.$suffix] = ['table' => 'purchase_returns', 'id' => $return];
        }

        return $refs;
    }

    private function seedJournals(): void
    {
        $seq = 1;
        $this->journal($seq++, 1, 1, 'Opening balance PT Nusantara Dagang Sejahtera', [
            ['1100', 25000000, 0],
            ['1110', 175000000, 0],
            ['1130', 80000000, 0],
            ['1150', 5000000, 0],
            ['1160', 12000000, 0],
            ['1210', 30000000, 0],
            ['3100', 0, 327000000],
        ], 'opening_balance', 'OB-NDS-'.$this->year);

        foreach ($this->openingStock() as $code => [$qty, $value]) {
            $this->stockMovement('OS-'.$code, 1, 1, 'opening', 'in', $code, $qty, $value, null);
        }

        $monthlyPurchases = [
            [1, 30000000, 3300000, 33300000, 25000000, 0],
            [2, 36000000, 3960000, 39960000, 55000000, 0],
            [3, 32000000, 3520000, 35520000, 43000000, 6000000],
            [4, 34000000, 3740000, 37740000, 28000000, 0],
            [5, 37000000, 4070000, 41070000, 31000000, 0],
            [6, 33000000, 3630000, 36630000, 30000000, 0],
            [7, 38000000, 4180000, 42180000, 34000000, 0],
            [8, 35000000, 3850000, 38850000, 22000000, 0],
            [9, 39000000, 4290000, 43290000, 36000000, 0],
            [10, 34000000, 3740000, 37740000, 32000000, 0],
            [11, 36000000, 3960000, 39960000, 34000000, 0],
            [12, 36000000, 3960000, 39960000, 0, 0],
        ];

        $monthlySales = [
            [1, 42000000, 4620000, 46620000, 29000000, 24000000, 5000000],
            [2, 47000000, 5170000, 52170000, 30500000, 61000000, 0],
            [3, 45000000, 4950000, 49950000, 29500000, 43000000, 0],
            [4, 48000000, 5280000, 53280000, 31200000, 39000000, 0],
            [5, 50000000, 5500000, 55500000, 32500000, 41000000, 0],
            [6, 49000000, 5390000, 54390000, 31800000, 42000000, 0],
            [7, 51000000, 5610000, 56610000, 33000000, 18000000, 0],
            [8, 52000000, 5720000, 57720000, 33700000, 45000000, 0],
            [9, 53000000, 5830000, 58830000, 34400000, 47000000, 0],
            [10, 54000000, 5940000, 59940000, 35100000, 48000000, 0],
            [11, 55000000, 6050000, 61050000, 35700000, 50000000, 0],
            [12, 54000000, 5940000, 59940000, 35100000, 0, 0],
        ];

        foreach ($monthlyPurchases as [$month, $inventory, $tax, $total, $payment, $deposit]) {
            $this->journal($seq++, $month, 9, 'Vendor bill inventory purchase', [
                ['1130', $inventory, 0],
                ['2140', $tax, 0],
                ['2100', 0, $total],
            ], 'purchase', 'VB-NDS-'.$this->year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT));
            if ($deposit > 0) {
                $this->journal($seq++, $month, 5, 'Vendor deposit paid', [
                    ['1140', $deposit, 0],
                    ['1110', 0, $deposit],
                ], 'purchase', 'VD-NDS-'.$this->year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT));
                $this->journal($seq++, $month, 9, 'Vendor deposit applied to bill', [
                    ['2100', $deposit, 0],
                    ['1140', 0, $deposit],
                ], 'purchase', 'VDA-NDS-'.$this->year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT));
            }
            if ($payment > 0) {
                $payMonth = min(12, $month + 1);
                $this->journal($seq++, $payMonth, 18, 'Vendor payment', [
                    ['2100', $payment, 0],
                    ['1110', 0, $payment],
                ], 'purchase', 'VP-NDS-'.$this->year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT));
            }
        }

        foreach ($monthlySales as [$month, $revenue, $tax, $total, $cogs, $receipt, $deposit]) {
            $this->journal($seq++, $month, 11, 'Sales invoice taxable goods', [
                ['1120', $total, 0],
                ['4100', 0, $revenue],
                ['2120', 0, $tax],
            ], 'sales', 'SI-NDS-'.$this->year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT));
            $this->journal($seq++, $month, 11, 'COGS for sales invoice', [
                ['5100', $cogs, 0],
                ['1130', 0, $cogs],
            ], 'inventory', 'COGS-NDS-'.$this->year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT));
            if ($deposit > 0) {
                $this->journal($seq++, $month, 6, 'Customer deposit received', [
                    ['1110', $deposit, 0],
                    ['2130', 0, $deposit],
                ], 'sales', 'CD-NDS-'.$this->year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT));
                $this->journal($seq++, $month, 11, 'Customer deposit applied', [
                    ['2130', $deposit, 0],
                    ['1120', 0, $deposit],
                ], 'sales', 'CDA-NDS-'.$this->year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT));
            }
            if ($receipt > 0) {
                $cashAccount = $month === 7 ? '1100' : '1110';
                $receiptMonth = min(12, $month + 1);
                $this->journal($seq++, $receiptMonth, 14, 'Customer receipt', [
                    [$cashAccount, $receipt, 0],
                    ['1120', 0, $receipt],
                ], 'sales', 'SR-NDS-'.$this->year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT));
            }
        }

        $this->journal($seq++, 4, 18, 'Sales return with output tax reversal', [
            ['4110', 2000000, 0],
            ['2120', 220000, 0],
            ['1120', 0, 2220000],
        ], 'sales', 'SRT-NDS-'.$this->year.'-04');
        $this->journal($seq++, 4, 18, 'Inventory restored from sales return', [
            ['1130', 1300000, 0],
            ['5100', 0, 1300000],
        ], 'inventory', 'SRT-STOCK-NDS-'.$this->year.'-04');
        $this->journal($seq++, 8, 20, 'Purchase return with input tax reversal', [
            ['2100', 1998000, 0],
            ['1130', 0, 1800000],
            ['2140', 0, 198000],
        ], 'purchase', 'PRT-NDS-'.$this->year.'-08');

        for ($month = 1; $month <= 12; $month++) {
            if ($month <= 11) {
                $this->journal($seq++, $month, 25, 'Monthly salary paid', [
                    ['6120', 8000000, 0],
                    ['1110', 0, 8000000],
                ], 'cash_bank', 'PAYROLL-NDS-'.$this->year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT));
            }
            $this->journal($seq++, $month, 16, 'Monthly electricity and water', [
                ['6130', 1000000, 0],
                ['1110', 0, 1000000],
            ], 'cash_bank', 'UTIL-NDS-'.$this->year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT));
            $this->journal($seq++, $month, 17, 'Monthly internet', [
                ['6140', 550000, 0],
                ['1110', 0, 550000],
            ], 'cash_bank', 'INET-NDS-'.$this->year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT));
            $this->journal($seq++, $month, 19, 'Monthly transport', [
                ['6150', 800000, 0],
                ['1100', 0, 800000],
            ], 'cash_bank', 'TRP-NDS-'.$this->year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT));
            $this->journal($seq++, $month, 28, 'Bank administration fee', [
                ['6160', 100000, 0],
                ['1110', 0, 100000],
            ], 'cash_bank', 'BANKFEE-NDS-'.$this->year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT));
            $this->journal($seq++, $month, 28, 'Bank interest income', [
                ['1110', 50000, 0],
                ['7100', 0, 50000],
            ], 'cash_bank', 'BINT-NDS-'.$this->year.'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT));
        }

        $this->journal($seq++, 6, 30, 'Petty cash correction', [
            ['6190', 250000, 0],
            ['1100', 0, 250000],
        ], 'journal', 'JV-CORR-NDS-'.$this->year.'-06');
        $this->journal($seq++, 9, 30, 'Bank admin fee correction', [
            ['6160', 150000, 0],
            ['1110', 0, 150000],
        ], 'journal', 'JV-CORR-NDS-'.$this->year.'-09');
        $this->journal($seq++, 12, 31, 'Amortisasi sewa dibayar dimuka', [
            ['6110', 12000000, 0],
            ['1160', 0, 12000000],
        ], 'adjustment', 'ADJ-RENT-NDS-'.$this->year);
        $this->journal($seq++, 12, 31, 'Pemakaian perlengkapan kantor', [
            ['6180', 4000000, 0],
            ['1150', 0, 4000000],
        ], 'adjustment', 'ADJ-SUPPLIES-NDS-'.$this->year);
        $this->journal($seq++, 12, 31, 'Penyusutan peralatan kantor', [
            ['6170', 6000000, 0],
            ['1220', 0, 6000000],
        ], 'adjustment', 'ADJ-DEPR-NDS-'.$this->year);
        $this->journal($seq++, 12, 31, 'Akrual gaji Desember', [
            ['6120', 8000000, 0],
            ['2150', 0, 8000000],
        ], 'adjustment', 'ADJ-SALARY-NDS-'.$this->year);
        $this->journal($seq++, 12, 31, 'Penyesuaian selisih persediaan hasil opname', [
            ['6190', 750000, 0],
            ['1130', 0, 750000],
        ], 'adjustment', 'ADJ-STOCK-NDS-'.$this->year);

        $this->seedSubsidiaryReconciliationDocuments();
        $this->seedCashBankDocuments();
        $this->seedStockMovementsFromJournalPlan();
    }

    private function seedSubsidiaryReconciliationDocuments(): void
    {
        $arDebitGap = $this->glAccountDebit('1120') - $this->salesDocumentDebit();
        $arCreditGap = $this->glAccountCredit('1120') - $this->salesDocumentCredit();
        $customerId = $this->contacts['CUS-NDS-005'];

        if ($arDebitGap > 0.01) {
            $this->document('sales_invoices', 'invoice_number', 'SI-NDS-'.$this->year.'-RECON', [
                'invoice_date' => $this->date(12, 30),
                'due_date' => $this->date(12, 31),
                'customer_id' => $customerId,
                'currency_code' => 'IDR',
                'is_taxable' => false,
                'status' => 'posted',
                'subtotal_before_discount' => $arDebitGap,
                'subtotal_after_discount' => $arDebitGap,
                'grand_total' => $arDebitGap,
                'paid_amount' => $arDebitGap,
                'balance_due' => 0,
                'posted_at' => $this->at(12, 30),
                'notes' => 'Subsidiary ledger balancing document for demo monthly sales journals.',
            ], 'sales_invoice_lines', 'sales_invoice_id', [
                'product_id' => $this->products['PRD-NDS-008'],
                'product_code' => 'PRD-NDS-008',
                'description' => 'Demo AR subsidiary balancing line',
                'quantity' => 1,
                'unit_id' => $this->productUnitId('PRD-NDS-008'),
                'unit_price' => $arDebitGap,
                'gross_amount' => $arDebitGap,
                'subtotal_after_discount' => $arDebitGap,
                'line_total' => $arDebitGap,
                'warehouse_id' => $this->warehouses['WH-NDS-UTM'],
                'department_id' => $this->departments['SLS'],
                'project_id' => $this->projects['NDS-YEAREND-2025'],
            ]);
        }

        if ($arCreditGap > 0.01) {
            $this->document('sales_receipts', 'receipt_number', 'SR-NDS-'.$this->year.'-RECON', [
                'receipt_date' => $this->date(12, 31),
                'customer_id' => $customerId,
                'cash_bank_account_id' => $this->accounts['1110'],
                'amount' => $arCreditGap,
                'status' => 'posted',
                'posted_at' => $this->at(12, 31),
                'notes' => 'Subsidiary ledger balancing receipt for demo monthly sales journals.',
            ], 'sales_receipt_lines', 'sales_receipt_id', [
                'amount' => $arCreditGap,
                'description' => 'Demo AR subsidiary balancing receipt',
            ]);
        }

        $apCreditGap = $this->glAccountCredit('2100') - $this->purchaseDocumentCredit();
        $apDebitGap = $this->glAccountDebit('2100') - $this->purchaseDocumentDebit();
        $vendorId = $this->contacts['SUP-NDS-001'];

        if ($apCreditGap > 0.01) {
            $this->document('vendor_bills', 'bill_number', 'VB-NDS-'.$this->year.'-RECON', [
                'bill_date' => $this->date(12, 30),
                'due_date' => $this->date(12, 31),
                'vendor_id' => $vendorId,
                'vendor_invoice_number' => 'INV-VB-NDS-'.$this->year.'-RECON',
                'currency_code' => 'IDR',
                'is_taxable' => false,
                'status' => 'posted',
                'subtotal_before_discount' => $apCreditGap,
                'subtotal_after_discount' => $apCreditGap,
                'grand_total' => $apCreditGap,
                'paid_amount' => $apCreditGap,
                'balance_due' => 0,
                'posted_at' => $this->at(12, 30),
                'notes' => 'Subsidiary ledger balancing document for demo monthly purchase journals.',
            ], 'vendor_bill_lines', 'vendor_bill_id', [
                'product_id' => $this->products['PRD-NDS-008'],
                'product_code' => 'PRD-NDS-008',
                'description' => 'Demo AP subsidiary balancing line',
                'quantity' => 1,
                'unit_id' => $this->productUnitId('PRD-NDS-008'),
                'unit_price' => $apCreditGap,
                'gross_amount' => $apCreditGap,
                'subtotal_after_discount' => $apCreditGap,
                'line_total' => $apCreditGap,
                'warehouse_id' => $this->warehouses['WH-NDS-UTM'],
                'department_id' => $this->departments['GDN'],
                'project_id' => $this->projects['NDS-YEAREND-2025'],
                'expense_account_id' => $this->accounts['1130'],
            ]);
        }

        if ($apDebitGap > 0.01) {
            $this->document('vendor_payments', 'payment_number', 'VP-NDS-'.$this->year.'-RECON', [
                'payment_date' => $this->date(12, 31),
                'vendor_id' => $vendorId,
                'cash_bank_account_id' => $this->accounts['1110'],
                'amount' => $apDebitGap,
                'status' => 'posted',
                'posted_at' => $this->at(12, 31),
                'notes' => 'Subsidiary ledger balancing payment for demo monthly purchase journals.',
            ], 'vendor_payment_lines', 'vendor_payment_id', [
                'amount' => $apDebitGap,
                'description' => 'Demo AP subsidiary balancing payment',
            ]);
        }
    }

    private function seedCashBankDocuments(): void
    {
        $this->document('cash_receipts', 'receipt_number', 'CR-NDS-'.$this->year.'-CAPITAL', [
            'receipt_date' => $this->date(1, 1),
            'cash_bank_account_id' => $this->accounts['1110'],
            'amount' => 175000000,
            'status' => 'posted',
            'posted_at' => $this->at(1, 1),
            'notes' => 'Setoran modal awal ke bank.',
        ], 'cash_receipt_lines', 'cash_receipt_id', [
            'account_id' => $this->accounts['3100'],
            'amount' => 175000000,
            'description' => 'Setoran modal awal',
            'department_id' => $this->departments['ADM'],
        ]);

        $this->document('bank_transfers', 'transfer_number', 'BT-NDS-'.$this->year.'-001', [
            'transfer_date' => $this->date(1, 15),
            'from_cash_bank_account_id' => $this->accounts['1110'],
            'to_cash_bank_account_id' => $this->accounts['1100'],
            'amount' => 10000000,
            'status' => 'posted',
            'posted_at' => $this->at(1, 15),
            'notes' => 'Transfer bank ke kas kecil.',
        ]);

        foreach ([3 => 2500000, 6 => 3000000, 9 => 3500000, 12 => 4000000] as $month => $amount) {
            $this->document('cash_payments', 'payment_number', 'CP-NDS-'.$this->year.'-RENT-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT), [
                'payment_date' => $this->date($month, 4),
                'cash_bank_account_id' => $this->accounts['1110'],
                'contact_id' => $this->contacts['OTH-NDS-BANK'] ?? null,
                'amount' => $amount,
                'status' => 'posted',
                'posted_at' => $this->at($month, 4),
                'notes' => 'Pembayaran operasional kas bank demo.',
            ], 'cash_payment_lines', 'cash_payment_id', [
                'account_id' => $this->accounts['6110'],
                'amount' => $amount,
                'description' => 'Pembayaran beban operasional',
                'department_id' => $this->departments['ADM'],
            ]);
        }
    }

    private function seedStockMovementsFromJournalPlan(): void
    {
        $this->stockMovement('GR-PRD1-JAN', 1, 8, 'goods_receipt', 'in', 'PRD-NDS-001', 500, 30000000, null);
        $this->stockMovement('GR-PRD2-FEB', 2, 10, 'goods_receipt', 'in', 'PRD-NDS-002', 480, 36000000, null);
        $this->stockMovement('GR-PRD6-MAR', 3, 8, 'goods_receipt', 'in', 'PRD-NDS-006', 260, 32000000, null);
        $this->stockMovement('GR-PRD3-APR', 4, 10, 'goods_receipt', 'in', 'PRD-NDS-003', 500, 34000000, null);
        $this->stockMovement('GR-PRD7-AUG', 8, 10, 'goods_receipt', 'in', 'PRD-NDS-007', 220, 35000000, null);
        $this->stockMovement('DO-JAN', 1, 11, 'delivery_order', 'out', 'PRD-NDS-001', 280, 29000000, null);
        $this->stockMovement('DO-FEB', 2, 11, 'delivery_order', 'out', 'PRD-NDS-002', 210, 30500000, null);
        $this->stockMovement('DO-MAR', 3, 11, 'delivery_order', 'out', 'PRD-NDS-006', 85, 29500000, null);
        $this->stockMovement('SRT-APR', 4, 18, 'sales_return', 'in', 'PRD-NDS-001', 22, 1300000, null, $this->warehouses['WH-NDS-RET']);
        $this->stockMovement('PRT-AUG', 8, 20, 'purchase_return', 'out', 'PRD-NDS-007', 112.5, 1800000, null);
        $this->stockMovement('ADJ-DEC', 12, 31, 'adjustment', 'out', 'PRD-NDS-008', 25, 750000, null);
    }

    private function stockMovement(string $suffix, int $month, int $day, string $type, string $direction, string $productCode, float $qty, float $value, ?int $sourceId, ?int $warehouseId = null): ?int
    {
        if (! $this->has('stock_movements') || ! $this->has('stock_movement_lines')) {
            return null;
        }

        $warehouseId ??= $this->warehouses['WH-NDS-UTM'];
        $productId = $this->products[$productCode];
        $unitId = $this->productUnitId($productCode);
        $beforeQty = $this->stockQty[$productCode] ?? 0;
        $beforeValue = $this->stockValue[$productCode] ?? 0;
        $afterQty = $direction === 'in' ? $beforeQty + $qty : $beforeQty - $qty;
        $afterValue = $direction === 'in' ? $beforeValue + $value : $beforeValue - $value;
        $avgBefore = $beforeQty > 0 ? $beforeValue / $beforeQty : 0;
        $avgAfter = $afterQty > 0 ? $afterValue / $afterQty : 0;
        $this->stockQty[$productCode] = $afterQty;
        $this->stockValue[$productCode] = $afterValue;

        $movement = $this->document('stock_movements', 'movement_number', 'SM-NDS-'.$this->year.'-'.$suffix, [
            'movement_date' => $this->date($month, $day),
            'movement_type' => $type,
            'direction' => $direction,
            'status' => 'posted',
            'source_type' => $type,
            'source_id' => $sourceId,
            'warehouse_id' => $warehouseId,
            'description' => 'Demo inventory '.$type,
            'total_quantity' => $qty,
            'total_value' => $value,
            'posted_at' => $this->at($month, $day),
        ], 'stock_movement_lines', 'stock_movement_id', [
            'movement_type' => $type,
            'direction' => $direction,
            'product_id' => $productId,
            'product_code' => $productCode,
            'warehouse_id' => $warehouseId,
            'unit_id' => $unitId,
            'quantity' => $qty,
            'unit_cost' => $qty > 0 ? $value / $qty : 0,
            'total_cost' => $value,
            'quantity_before' => $beforeQty,
            'quantity_after' => $afterQty,
            'average_cost_before' => $avgBefore,
            'average_cost_after' => $avgAfter,
            'value_before' => $beforeValue,
            'value_after' => $afterValue,
            'department_id' => $this->departments['GDN'] ?? null,
        ]);

        if ($movement) {
            $this->lastStockMovement[$productCode] = $movement;
        }

        return $movement;
    }

    private function seedInventoryBalances(): void
    {
        if (! $this->has('stock_balances')) {
            return;
        }

        $inventoryGl = $this->accountBalance('1130');
        $stockValue = array_sum($this->stockValue);
        $difference = round($inventoryGl - $stockValue, 2);
        if (abs($difference) >= 0.01 && isset($this->stockValue['PRD-NDS-008'])) {
            $this->stockValue['PRD-NDS-008'] += $difference;
        }

        foreach ($this->products as $code => $productId) {
            $qty = $this->stockQty[$code] ?? 0;
            $value = $this->stockValue[$code] ?? 0;
            if ($qty <= 0 && abs($value) < 0.01) {
                continue;
            }

            $this->upsertComposite('stock_balances', [
                'product_id' => $productId,
                'warehouse_id' => $this->warehouses['WH-NDS-UTM'],
            ], [
                'quantity_on_hand' => $qty,
                'quantity_reserved' => 0,
                'quantity_available' => $qty,
                'average_cost' => $qty > 0 ? $value / $qty : 0,
                'total_value' => $value,
                'last_movement_id' => $this->lastStockMovement[$code] ?? null,
                'last_movement_at' => $this->at(12, 31),
                'metadata' => $this->metadata(),
            ]);
        }

        $adjustment = $this->document('stock_adjustments', 'adjustment_number', 'SA-NDS-'.$this->year.'-001', [
            'adjustment_date' => $this->date(12, 31),
            'warehouse_id' => $this->warehouses['WH-NDS-UTM'],
            'status' => 'posted',
            'reason' => 'Selisih opname akhir tahun.',
            'stock_movement_id' => $this->lastStockMovement['PRD-NDS-008'] ?? null,
            'approved_at' => $this->at(12, 31),
            'posted_at' => $this->at(12, 31),
        ], 'stock_adjustment_lines', 'stock_adjustment_id', [
            'product_id' => $this->products['PRD-NDS-008'],
            'warehouse_id' => $this->warehouses['WH-NDS-UTM'],
            'unit_id' => $this->productUnitId('PRD-NDS-008'),
            'adjustment_type' => 'decrease',
            'quantity' => 25,
            'unit_cost' => 30000,
            'total_cost' => 750000,
            'reason' => 'Selisih fisik minor akhir tahun.',
        ]);

        $this->document('stock_opnames', 'opname_number', 'OPN-NDS-'.$this->year.'-001', [
            'opname_date' => $this->date(12, 31),
            'warehouse_id' => $this->warehouses['WH-NDS-UTM'],
            'status' => 'finalized',
            'counted_at' => $this->at(12, 31),
            'finalized_at' => $this->at(12, 31),
            'stock_movement_id' => $this->lastStockMovement['PRD-NDS-008'] ?? null,
            'notes' => 'Stock opname demo akhir tahun.',
        ], 'stock_opname_lines', 'stock_opname_id', [
            'product_id' => $this->products['PRD-NDS-008'],
            'warehouse_id' => $this->warehouses['WH-NDS-UTM'],
            'unit_id' => $this->productUnitId('PRD-NDS-008'),
            'system_quantity' => 135,
            'physical_quantity' => 110,
            'difference_quantity' => -25,
            'average_cost' => 30000,
            'difference_value' => -750000,
            'counted_at' => $this->at(12, 31),
        ]);
    }

    private function seedBankReconciliation(): void
    {
        if (! $this->has('bank_reconciliations') || ! $this->has('bank_reconciliation_lines')) {
            return;
        }

        $ending = $this->accountBalance('1110');
        $reconciliationId = $this->document('bank_reconciliations', 'reconciliation_number', 'BR-NDS-'.$this->year.'-12', [
            'cash_bank_account_id' => $this->accounts['1110'],
            'statement_start_date' => $this->date(12, 1),
            'statement_end_date' => $this->date(12, 31),
            'statement_opening_balance' => 0,
            'statement_ending_balance' => $ending,
            'status' => 'draft',
            'notes' => 'Demo rekonsiliasi bank akhir tahun.',
        ]);

        DB::connection('tenant')->table('bank_reconciliation_lines')->where('bank_reconciliation_id', $reconciliationId)->delete();
        $lines = DB::connection('tenant')->table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('jel.account_id', $this->accounts['1110'])
            ->where('je.source_type', self::SEEDED_BY)
            ->where('je.status', 'posted')
            ->orderBy('je.journal_date')
            ->limit(12)
            ->get(['jel.id as line_id', 'je.id as journal_id', 'je.journal_number', 'je.journal_date', 'je.description', 'jel.debit', 'jel.credit']);

        foreach ($lines as $index => $line) {
            DB::connection('tenant')->table('bank_reconciliation_lines')->insert($this->filter('bank_reconciliation_lines', [
                'bank_reconciliation_id' => $reconciliationId,
                'journal_entry_id' => $line->journal_id,
                'journal_entry_line_id' => $line->line_id,
                'journal_date' => $line->journal_date,
                'journal_number' => $line->journal_number,
                'description' => $line->description,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'is_cleared' => true,
                'cleared_date' => $this->date(12, 31),
                'line_order' => $index + 1,
                'metadata' => $this->metadata(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    /**
     * @param array<int,array{0:string,1:float,2:float}> $lines
     */
    private function journal(int $sequence, int $month, int $day, string $description, array $lines, string $sourceModule, string $sourceNumber): int
    {
        if (! $this->has('journal_entries') || ! $this->has('journal_entry_lines')) {
            throw new RuntimeException('journal_entries and journal_entry_lines tables are required.');
        }

        $debit = array_sum(array_map(fn (array $line): float => (float) $line[1], $lines));
        $credit = array_sum(array_map(fn (array $line): float => (float) $line[2], $lines));
        if (abs($debit - $credit) >= 0.01) {
            throw new RuntimeException("Unbalanced journal {$sourceNumber}: debit {$debit}, credit {$credit}.");
        }

        $number = sprintf('JV-NDS-%d-%04d', $this->year, $sequence);
        $journalId = $this->upsert('journal_entries', 'journal_number', $number, [
            'journal_date' => $this->date($month, $day),
            'description' => $description,
            'status' => 'posted',
            'revision_no' => 1,
            'source_type' => self::SEEDED_BY,
            'source_number' => $sourceNumber,
            'source_revision' => 1,
            'source_module' => $sourceModule,
            'is_system_generated' => true,
            'is_obsolete' => false,
            'posted_at' => $this->at($month, $day),
            'metadata' => $this->metadata(['total_debit' => $debit, 'total_credit' => $credit]),
        ]);
        $this->journals[$sourceNumber] = $journalId;

        DB::connection('tenant')->table('journal_entry_lines')->where('journal_entry_id', $journalId)->delete();
        foreach ($lines as $order => [$accountCode, $lineDebit, $lineCredit]) {
            DB::connection('tenant')->table('journal_entry_lines')->insert($this->filter('journal_entry_lines', [
                'journal_entry_id' => $journalId,
                'account_id' => $this->accounts[$accountCode],
                'description' => $description,
                'debit' => $lineDebit,
                'credit' => $lineCredit,
                'line_order' => $order + 1,
                'department_id' => $this->departments[$sourceModule === 'sales' ? 'SLS' : ($sourceModule === 'inventory' ? 'GDN' : 'ADM')] ?? null,
                'project_id' => $this->projects['NDS-YEAREND-2025'] ?? null,
                'metadata' => $this->metadata(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        return $journalId;
    }

    /**
     * @param array<string, array{table:string,id:int}> $documents
     */
    private function linkDocumentJournals(array $documents): void
    {
        foreach ($documents as $key => $document) {
            $journalNumber = $this->journalSourceNumberForDocumentKey($key);
            if (! $journalNumber || ! isset($this->journals[$journalNumber])) {
                continue;
            }

            $table = $document['table'];
            if (! Schema::connection('tenant')->hasColumn($table, 'journal_entry_id')) {
                continue;
            }

            DB::connection('tenant')->table($table)
                ->where('id', $document['id'])
                ->update(['journal_entry_id' => $this->journals[$journalNumber]]);
        }
    }

    private function journalSourceNumberForDocumentKey(string $key): ?string
    {
        if (preg_match('/sales_invoice_(\d+)/', $key, $m)) {
            return 'SI-NDS-'.$this->year.'-'.str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/sales_receipt_(\d+)/', $key, $m)) {
            return 'SR-NDS-'.$this->year.'-'.str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/customer_deposit_(\d+)/', $key, $m)) {
            return 'CD-NDS-'.$this->year.'-'.str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/sales_return_(\d+)/', $key, $m)) {
            return 'SRT-NDS-'.$this->year.'-'.str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/vendor_bill_(\d+)/', $key, $m)) {
            return 'VB-NDS-'.$this->year.'-'.str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/vendor_payment_(\d+)/', $key, $m)) {
            return 'VP-NDS-'.$this->year.'-'.str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/vendor_deposit_(\d+)/', $key, $m)) {
            return 'VD-NDS-'.$this->year.'-'.str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/purchase_return_(\d+)/', $key, $m)) {
            return 'PRT-NDS-'.$this->year.'-'.str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }

        return null;
    }

    /**
     * @return array<int,array{0:string,1:string,2:string,3:string,4:bool}>
     */
    private function chartOfAccounts(): array
    {
        return [
            ['1100', 'Kas', 'asset', 'debit', true],
            ['1110', 'Bank', 'asset', 'debit', true],
            ['1120', 'Piutang Usaha', 'asset', 'debit', false],
            ['1130', 'Persediaan Barang Dagang', 'asset', 'debit', false],
            ['1140', 'Uang Muka Pembelian / Vendor Deposit', 'asset', 'debit', false],
            ['1150', 'Perlengkapan Kantor', 'asset', 'debit', false],
            ['1160', 'Sewa Dibayar Dimuka', 'asset', 'debit', false],
            ['1210', 'Peralatan Kantor', 'asset', 'debit', false],
            ['1220', 'Akumulasi Penyusutan Peralatan Kantor', 'asset', 'debit', false],
            ['2100', 'Hutang Usaha', 'liability', 'credit', false],
            ['2120', 'PPN Keluaran', 'liability', 'credit', false],
            ['2140', 'PPN Masukan', 'asset', 'debit', false],
            ['2150', 'Beban Yang Masih Harus Dibayar', 'liability', 'credit', false],
            ['2160', 'Hutang Pajak', 'liability', 'credit', false],
            ['2130', 'Uang Muka Pelanggan', 'liability', 'credit', false],
            ['3100', 'Modal Disetor', 'equity', 'credit', false],
            ['3200', 'Laba Ditahan', 'equity', 'credit', false],
            ['3300', 'Laba Tahun Berjalan', 'equity', 'credit', false],
            ['4100', 'Penjualan Barang Dagang', 'revenue', 'credit', false],
            ['4110', 'Retur Penjualan', 'revenue', 'credit', false],
            ['4120', 'Potongan Penjualan', 'revenue', 'credit', false],
            ['5100', 'Harga Pokok Penjualan', 'expense', 'debit', false],
            ['5110', 'Retur Pembelian', 'expense', 'credit', false],
            ['5120', 'Selisih Persediaan', 'expense', 'debit', false],
            ['6110', 'Beban Sewa', 'expense', 'debit', false],
            ['6120', 'Beban Gaji', 'expense', 'debit', false],
            ['6130', 'Beban Listrik dan Air', 'expense', 'debit', false],
            ['6140', 'Beban Internet', 'expense', 'debit', false],
            ['6150', 'Beban Transportasi', 'expense', 'debit', false],
            ['6160', 'Beban Administrasi Bank', 'expense', 'debit', false],
            ['6170', 'Beban Penyusutan', 'expense', 'debit', false],
            ['6180', 'Beban Perlengkapan', 'expense', 'debit', false],
            ['6190', 'Beban Lain-lain', 'expense', 'debit', false],
            ['7100', 'Pendapatan Bunga Bank', 'revenue', 'credit', false],
            ['8100', 'Beban Pajak / Beban Lain', 'expense', 'debit', false],
        ];
    }

    /**
     * @return array<string,array{0:string,1:string,2:bool}>
     */
    private function accountMappings(): array
    {
        return [
            'sales.accounts_receivable' => ['sales', '1120', true],
            'sales.revenue' => ['sales', '4100', true],
            'sales.discount' => ['sales', '4120', false],
            'sales.return' => ['sales', '4110', false],
            'sales.tax_output' => ['sales', '2120', false],
            'sales.customer_deposit' => ['sales', '2130', true],
            'sales.default_cash_bank' => ['sales', '1110', false],
            'purchase.accounts_payable' => ['purchase', '2100', true],
            'purchase.expense' => ['purchase', '5100', true],
            'purchase.default_purchase' => ['purchase', '1130', false],
            'purchase.inventory_interim' => ['purchase', '2150', false],
            'purchase.tax_input' => ['purchase', '2140', false],
            'purchase.discount' => ['purchase', '5110', false],
            'purchase.return' => ['purchase', '5110', false],
            'purchase.vendor_deposit' => ['purchase', '1140', false],
            'purchase.default_cash_bank' => ['purchase', '1110', false],
            'inventory.asset' => ['inventory', '1130', true],
            'inventory.cogs' => ['inventory', '5100', true],
            'inventory.adjustment_loss' => ['inventory', '5120', false],
            'inventory.write_off' => ['inventory', '5120', false],
            'cash_bank.default_cash' => ['cash_bank', '1100', true],
            'cash_bank.default_bank' => ['cash_bank', '1110', true],
            'cash_bank.bank_admin_fee' => ['cash_bank', '6160', false],
            'cash_bank.bank_interest_income' => ['cash_bank', '7100', false],
            'opening_balance.equity' => ['opening_balance', '3100', true],
            'closing.retained_earnings' => ['closing', '3200', true],
            'closing.current_year_earnings' => ['closing', '3300', true],
        ];
    }

    /**
     * @return array<string,array{0:float,1:float}>
     */
    private function openingStock(): array
    {
        return [
            'PRD-NDS-001' => [320, 19200000],
            'PRD-NDS-002' => [260, 19500000],
            'PRD-NDS-003' => [450, 6750000],
            'PRD-NDS-004' => [600, 5700000],
            'PRD-NDS-005' => [120, 9000000],
            'PRD-NDS-006' => [110, 10780000],
            'PRD-NDS-007' => [220, 3520000],
            'PRD-NDS-008' => [190, 5550000],
        ];
    }

    /**
     * @param array<string,int> $categories
     * @return array<int,array{0:string,1:string,2:string,3:float,4:float,5:int|null}>
     */
    private function productCatalog(array $categories): array
    {
        return [
            ['PRD-NDS-001', 'Beras Premium 5kg', 'pack', 60000, 72000, $categories['Bahan Pokok NDS'] ?? null],
            ['PRD-NDS-002', 'Minyak Goreng 2L', 'liter', 31500, 38000, $categories['Bahan Pokok NDS'] ?? null],
            ['PRD-NDS-003', 'Gula Pasir 1kg', 'kg', 15000, 18000, $categories['Bahan Pokok NDS'] ?? null],
            ['PRD-NDS-004', 'Tepung Terigu 1kg', 'kg', 9500, 14000, $categories['Bahan Pokok NDS'] ?? null],
            ['PRD-NDS-005', 'Kopi Sachet Box', 'dus', 75000, 95000, $categories['Bahan Pokok NDS'] ?? null],
            ['PRD-NDS-006', 'Mie Instan Karton', 'karton', 98000, 125000, $categories['Bahan Pokok NDS'] ?? null],
            ['PRD-NDS-007', 'Sabun Cair 1L', 'liter', 16000, 23000, $categories['Kebutuhan Rumah Tangga NDS'] ?? null],
            ['PRD-NDS-008', 'Air Mineral Karton', 'karton', 29200, 42000, $categories['Bahan Pokok NDS'] ?? null],
        ];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function itemLine(string $productCode, float $qty, float $unitPrice, float $gross, float $base, float $total, ?int $unitId, int $warehouseId, int $departmentId, int $projectId, float $tax, array $extra = []): array
    {
        return array_merge([
            'product_id' => $this->products[$productCode],
            'product_code' => $productCode,
            'description' => $this->productName($productCode),
            'quantity' => $qty,
            'unit_id' => $unitId,
            'unit_price' => $unitPrice,
            'gross_amount' => $gross,
            'tax_rate' => 11,
            'tax_amount' => $tax,
            'subtotal_after_discount' => $base,
            'line_total' => $total,
            'warehouse_id' => $warehouseId,
            'department_id' => $departmentId,
            'project_id' => $projectId,
        ], $extra);
    }

    private function purchaseLine(string $productCode, float $qty, float $unitPrice, float $base, float $total, ?int $unitId, int $warehouseId, int $departmentId, int $projectId, float $tax, array $extra = []): array
    {
        return array_merge([
            'product_id' => $this->products[$productCode],
            'product_code' => $productCode,
            'description' => $this->productName($productCode),
            'quantity' => $qty,
            'unit_id' => $unitId,
            'unit_price' => $unitPrice,
            'gross_amount' => $base,
            'tax_rate' => 11,
            'tax_amount' => $tax,
            'subtotal_after_discount' => $base,
            'line_total' => $total,
            'warehouse_id' => $warehouseId,
            'department_id' => $departmentId,
            'project_id' => $projectId,
            'expense_account_id' => $this->accounts['1130'],
        ], $extra);
    }

    private function productName(string $productCode): string
    {
        return [
            'PRD-NDS-001' => 'Beras Premium 5kg',
            'PRD-NDS-002' => 'Minyak Goreng 2L',
            'PRD-NDS-003' => 'Gula Pasir 1kg',
            'PRD-NDS-004' => 'Tepung Terigu 1kg',
            'PRD-NDS-005' => 'Kopi Sachet Box',
            'PRD-NDS-006' => 'Mie Instan Karton',
            'PRD-NDS-007' => 'Sabun Cair 1L',
            'PRD-NDS-008' => 'Air Mineral Karton',
        ][$productCode] ?? $productCode;
    }

    private function productUnitId(string $productCode): ?int
    {
        $unitCode = [
            'PRD-NDS-001' => 'pack',
            'PRD-NDS-002' => 'liter',
            'PRD-NDS-003' => 'kg',
            'PRD-NDS-004' => 'kg',
            'PRD-NDS-005' => 'dus',
            'PRD-NDS-006' => 'karton',
            'PRD-NDS-007' => 'liter',
            'PRD-NDS-008' => 'karton',
        ][$productCode] ?? 'pcs';

        return $this->units[$unitCode] ?? null;
    }

    /**
     * @param array<int, array{0:string,1:array<string,mixed>}> $rows
     * @param array<string, int> $ids
     */
    private function seedRows(string $table, string $key, array $rows, array &$ids): void
    {
        if (! $this->has($table)) {
            return;
        }

        foreach ($rows as [$value, $data]) {
            $ids[$value] = $this->upsert($table, $key, $value, array_merge([
                'is_active' => true,
                'metadata' => $this->metadata(),
            ], $data));
        }
    }

    private function document(string $table, string $key, string|int|null $value, array $header, ?string $lineTable = null, ?string $foreignKey = null, array $line = [], array $composite = []): ?int
    {
        if (! $this->has($table)) {
            return null;
        }

        $id = $composite !== []
            ? $this->upsertComposite($table, $composite, array_merge($header, ['metadata' => $this->metadata()]))
            : $this->upsert($table, $key, $value, array_merge($header, ['metadata' => $this->metadata()]));

        if ($lineTable && $foreignKey && $this->has($lineTable)) {
            DB::connection('tenant')->table($lineTable)->where($foreignKey, $id)->delete();
            DB::connection('tenant')->table($lineTable)->insert($this->filter($lineTable, array_merge($line, [
                $foreignKey => $id,
                'sort_order' => 1,
                'line_order' => 1,
                'metadata' => $this->metadata(),
                'created_at' => now(),
                'updated_at' => now(),
            ])));
        }

        return $id;
    }

    private function upsert(string $table, string $key, string|int|null $value, array $payload): int
    {
        return $this->upsertComposite($table, [$key => $value], $payload);
    }

    private function upsertComposite(string $table, array $match, array $payload): int
    {
        $payload = $this->filter($table, array_merge($payload, ['updated_at' => now()]));
        $match = $this->filter($table, $match);
        $exists = DB::connection('tenant')->table($table)->where($match)->first();
        if ($exists) {
            DB::connection('tenant')->table($table)->where('id', $exists->id)->update($payload);
            return (int) $exists->id;
        }

        return (int) DB::connection('tenant')->table($table)->insertGetId($this->filter($table, array_merge($match, $payload, ['created_at' => now()])));
    }

    private function has(string $table): bool
    {
        if (Schema::connection('tenant')->hasTable($table)) {
            $this->seededTables[] = $table;
            return true;
        }

        $this->skippedTables[] = $table;
        return false;
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function filter(string $table, array $values): array
    {
        return array_filter($values, fn (string $column): bool => Schema::connection('tenant')->hasColumn($table, $column), ARRAY_FILTER_USE_KEY);
    }

    private function metadata(array $additional = []): string
    {
        return json_encode(array_merge([
            'seeded_by' => self::SEEDED_BY,
            'company' => 'PT Nusantara Dagang Sejahtera',
            'year' => $this->year,
        ], $additional), JSON_THROW_ON_ERROR);
    }

    private function doc(string $prefix, string $suffix): string
    {
        return sprintf('%s-NDS-%d-%s', $prefix, $this->year, $suffix);
    }

    private function date(int $month, int $day): string
    {
        return Carbon::create($this->year, $month, $day)->toDateString();
    }

    private function at(int $month, int $day): string
    {
        return Carbon::parse($this->date($month, $day).' 12:00:00')->toDateTimeString();
    }

    private function accountBalance(string $accountCode): float
    {
        $accountId = $this->accounts[$accountCode];
        $row = DB::connection('tenant')->table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.is_obsolete', 0)
            ->where('jel.account_id', $accountId)
            ->selectRaw('COALESCE(SUM(jel.debit),0) as debit, COALESCE(SUM(jel.credit),0) as credit')
            ->first();

        return (float) ($row->debit ?? 0) - (float) ($row->credit ?? 0);
    }

    private function glAccountDebit(string $accountCode): float
    {
        return (float) $this->glAccountSums($accountCode)->debit;
    }

    private function glAccountCredit(string $accountCode): float
    {
        return (float) $this->glAccountSums($accountCode)->credit;
    }

    private function glAccountSums(string $accountCode): object
    {
        return DB::connection('tenant')->table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->where('je.is_obsolete', 0)
            ->where('je.source_type', self::SEEDED_BY)
            ->where('coa.account_code', $accountCode)
            ->selectRaw('COALESCE(SUM(jel.debit),0) as debit, COALESCE(SUM(jel.credit),0) as credit')
            ->first();
    }

    private function salesDocumentDebit(): float
    {
        if (! $this->has('sales_invoices')) {
            return 0;
        }

        return (float) DB::connection('tenant')->table('sales_invoices')
            ->where('metadata', 'like', '%"seeded_by":"'.self::SEEDED_BY.'"%')
            ->whereBetween('invoice_date', [$this->date(1, 1), $this->date(12, 31)])
            ->sum('grand_total');
    }

    private function salesDocumentCredit(): float
    {
        $total = 0.0;
        if ($this->has('sales_receipts')) {
            $total += (float) DB::connection('tenant')->table('sales_receipts')
                ->where('metadata', 'like', '%"seeded_by":"'.self::SEEDED_BY.'"%')
                ->whereBetween('receipt_date', [$this->date(1, 1), $this->date(12, 31)])
                ->sum('amount');
        }
        if ($this->has('customer_deposit_allocations')) {
            $total += (float) DB::connection('tenant')->table('customer_deposit_allocations')
                ->where('metadata', 'like', '%"seeded_by":"'.self::SEEDED_BY.'"%')
                ->whereBetween('allocation_date', [$this->date(1, 1), $this->date(12, 31)])
                ->sum('allocated_amount');
        }
        if ($this->has('sales_returns')) {
            $total += (float) DB::connection('tenant')->table('sales_returns')
                ->where('metadata', 'like', '%"seeded_by":"'.self::SEEDED_BY.'"%')
                ->whereBetween('return_date', [$this->date(1, 1), $this->date(12, 31)])
                ->sum('grand_total');
        }

        return $total;
    }

    private function purchaseDocumentCredit(): float
    {
        if (! $this->has('vendor_bills')) {
            return 0;
        }

        return (float) DB::connection('tenant')->table('vendor_bills')
            ->where('metadata', 'like', '%"seeded_by":"'.self::SEEDED_BY.'"%')
            ->whereBetween('bill_date', [$this->date(1, 1), $this->date(12, 31)])
            ->sum('grand_total');
    }

    private function purchaseDocumentDebit(): float
    {
        $total = 0.0;
        if ($this->has('vendor_payments')) {
            $total += (float) DB::connection('tenant')->table('vendor_payments')
                ->where('metadata', 'like', '%"seeded_by":"'.self::SEEDED_BY.'"%')
                ->whereBetween('payment_date', [$this->date(1, 1), $this->date(12, 31)])
                ->sum('amount');
        }
        if ($this->has('vendor_deposit_allocations')) {
            $total += (float) DB::connection('tenant')->table('vendor_deposit_allocations')
                ->where('metadata', 'like', '%"seeded_by":"'.self::SEEDED_BY.'"%')
                ->whereBetween('allocation_date', [$this->date(1, 1), $this->date(12, 31)])
                ->sum('allocated_amount');
        }
        if ($this->has('purchase_returns')) {
            $total += (float) DB::connection('tenant')->table('purchase_returns')
                ->where('metadata', 'like', '%"seeded_by":"'.self::SEEDED_BY.'"%')
                ->whereBetween('return_date', [$this->date(1, 1), $this->date(12, 31)])
                ->sum('grand_total');
        }

        return $total;
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(): array
    {
        $totals = DB::connection('tenant')->table('journal_entry_lines as lines')
            ->join('journal_entries as journals', 'journals.id', '=', 'lines.journal_entry_id')
            ->where('journals.source_type', self::SEEDED_BY)
            ->where('journals.status', 'posted')
            ->selectRaw('COALESCE(SUM(lines.debit), 0) as debit, COALESCE(SUM(lines.credit), 0) as credit')
            ->first();

        $unbalanced = DB::connection('tenant')->table('journal_entries as journals')
            ->join('journal_entry_lines as lines', 'lines.journal_entry_id', '=', 'journals.id')
            ->where('journals.source_type', self::SEEDED_BY)
            ->where('journals.status', 'posted')
            ->groupBy('journals.id')
            ->havingRaw('ABS(COALESCE(SUM(lines.debit), 0) - COALESCE(SUM(lines.credit), 0)) >= 0.01')
            ->count();

        $ar = $this->accountBalance('1120');
        $ap = -1 * $this->accountBalance('2100');

        return [
            'year' => $this->year,
            'seeded_tables' => array_values(array_unique($this->seededTables)),
            'skipped_tables' => array_values(array_unique($this->skippedTables)),
            'journal_entries' => count($this->journals),
            'trial_balance' => [
                'debit' => (float) ($totals->debit ?? 0),
                'credit' => (float) ($totals->credit ?? 0),
                'balanced' => abs((float) ($totals->debit ?? 0) - (float) ($totals->credit ?? 0)) < 0.01,
                'unbalanced_journals' => $unbalanced,
            ],
            'balances' => [
                'cash' => $this->accountBalance('1100'),
                'bank' => $this->accountBalance('1110'),
                'accounts_receivable' => $ar,
                'accounts_payable' => $ap,
                'inventory' => $this->accountBalance('1130'),
            ],
        ];
    }
}
