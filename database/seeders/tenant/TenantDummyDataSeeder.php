<?php

namespace Database\Seeders\tenant;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class TenantDummyDataSeeder extends Seeder
{
    private const SEEDED_BY = 'tenant_dummy_full_cycle_january_2026';

    private string $period = '2026-01';

    /** @var array<int, string> */
    private array $seededTables = [];

    /** @var array<int, string> */
    private array $skippedTables = [];

    /** @var array<string, int> */
    private array $accounts = [];

    /** @var array<string, int> */
    private array $contacts = [];

    /** @var array<string, int> */
    private array $products = [];

    /** @var array<string, int> */
    private array $units = [];

    /** @var array<string, int> */
    private array $warehouses = [];

    /** @var array<string, int> */
    private array $departments = [];

    /** @var array<string, int> */
    private array $projects = [];

    /** @var array<string, int> */
    private array $journals = [];

    public function run(): void
    {
        $this->seed('2026-01');
    }

    /**
     * @return array{period:string,seeded_tables:array<int,string>,skipped_tables:array<int,string>,journal_entries:int,trial_balance:array<string,float|bool>}
     */
    public function seed(string $period = '2026-01'): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new RuntimeException('Period must use YYYY-MM format.');
        }

        $this->period = $period;
        $this->seededTables = [];
        $this->skippedTables = [];
        $this->accounts = [];
        $this->contacts = [];
        $this->products = [];
        $this->units = [];
        $this->warehouses = [];
        $this->departments = [];
        $this->projects = [];
        $this->journals = [];

        DB::connection('tenant')->transaction(function (): void {
            $this->seedMasterData();
            $documents = $this->seedModuleDocuments();
            $this->seedJournalCycle();
            $this->linkDocumentJournals($documents);
            $this->seedInventoryBalances();
            $this->seedBankReconciliationLines();
        });

        $totals = DB::connection('tenant')->table('journal_entry_lines as lines')
            ->join('journal_entries as journals', 'journals.id', '=', 'lines.journal_entry_id')
            ->where('journals.journal_number', 'like', $this->prefix('JRN').'%')
            ->where('journals.status', 'posted')
            ->selectRaw('COALESCE(SUM(lines.debit), 0) as debit, COALESCE(SUM(lines.credit), 0) as credit')
            ->first();

        $debit = (float) ($totals->debit ?? 0);
        $credit = (float) ($totals->credit ?? 0);

        return [
            'period' => $this->period,
            'seeded_tables' => array_values(array_unique($this->seededTables)),
            'skipped_tables' => array_values(array_unique($this->skippedTables)),
            'journal_entries' => count($this->journals),
            'trial_balance' => [
                'debit' => $debit,
                'credit' => $credit,
                'balanced' => abs($debit - $credit) < 0.01,
            ],
        ];
    }

    private function seedMasterData(): void
    {
        $coa = [
            ['1100', 'Kas Kecil', 'asset', 'debit', true],
            ['1110', 'Bank Operasional', 'asset', 'debit', true],
            ['1120', 'Piutang Usaha', 'asset', 'debit', false],
            ['1130', 'Persediaan Barang', 'asset', 'debit', false],
            ['1140', 'Uang Muka Vendor', 'asset', 'debit', false],
            ['1150', 'Perlengkapan', 'asset', 'debit', false],
            ['1210', 'Peralatan Kantor', 'asset', 'debit', false],
            ['1220', 'Akumulasi Penyusutan', 'asset', 'credit', false],
            ['2100', 'Hutang Usaha', 'liability', 'credit', false],
            ['2110', 'Hutang Biaya', 'liability', 'credit', false],
            ['2120', 'PPN Keluaran', 'liability', 'credit', false],
            ['2130', 'Uang Muka Pelanggan', 'liability', 'credit', false],
            ['2140', 'PPN Masukan', 'asset', 'debit', false],
            ['2150', 'Inventory Interim / GRNI', 'liability', 'credit', false],
            ['3100', 'Modal Pemilik', 'equity', 'credit', false],
            ['3200', 'Laba Ditahan', 'equity', 'credit', false],
            ['4100', 'Penjualan Barang', 'revenue', 'credit', false],
            ['4110', 'Retur Penjualan', 'revenue', 'credit', false],
            ['5100', 'Harga Pokok Penjualan', 'expense', 'debit', false],
            ['5110', 'Retur Pembelian', 'expense', 'credit', false],
            ['6100', 'Beban Listrik', 'expense', 'debit', false],
            ['6110', 'Beban Internet', 'expense', 'debit', false],
            ['6120', 'Beban Gaji', 'expense', 'debit', false],
            ['6130', 'Beban Transport', 'expense', 'debit', false],
            ['6140', 'Beban Administrasi Bank', 'expense', 'debit', false],
            ['6150', 'Beban Penyusutan', 'expense', 'debit', false],
            ['6160', 'Beban Perlengkapan', 'expense', 'debit', false],
        ];

        if ($this->has('chart_of_accounts')) {
            foreach ($coa as [$code, $name, $type, $normal, $cash]) {
                $id = $this->upsert('chart_of_accounts', 'account_code', $code, [
                    'account_name' => $name,
                    'account_type' => $type,
                    'normal_balance' => $normal,
                    'is_cash_bank' => $cash,
                    'is_active' => true,
                    'is_system_default' => false,
                    'description' => 'Demo account for January 2026 full accounting cycle.',
                    'metadata' => $this->metadata(),
                ]);
                $this->accounts[$code] = $id;
            }
        } else {
            throw new RuntimeException('chart_of_accounts table is required to seed the accounting cycle.');
        }

        $this->seedRows('contacts', 'contact_code', [
            ['CUS-DMY-001', ['name' => 'PT Retail Sejahtera', 'contact_type' => 'customer', 'is_customer' => true, 'email' => 'retail@example.test']],
            ['CUS-DMY-002', ['name' => 'CV Warung Bersama', 'contact_type' => 'customer', 'is_customer' => true, 'email' => 'warung@example.test']],
            ['CUS-DMY-003', ['name' => 'UD Makmur', 'contact_type' => 'customer', 'is_customer' => true]],
            ['SUP-DMY-001', ['name' => 'PT Gas Nusantara', 'contact_type' => 'supplier', 'is_supplier' => true, 'email' => 'vendor@example.test']],
            ['SUP-DMY-002', ['name' => 'CV Kemasan Jaya', 'contact_type' => 'supplier', 'is_supplier' => true]],
            ['EMP-DMY-001', ['name' => 'Rina Finance', 'contact_type' => 'employee', 'is_employee' => true]],
            ['EMP-DMY-002', ['name' => 'Budi Warehouse', 'contact_type' => 'employee', 'is_employee' => true]],
            ['OTH-DMY-001', ['name' => 'Bank Demo Indonesia', 'contact_type' => 'other']],
        ], $this->contacts);

        $this->seedRows('units', 'code', [
            ['PCS', ['name' => 'Pieces', 'precision' => 0]],
            ['TAB', ['name' => 'Tabung', 'precision' => 0]],
            ['BOX', ['name' => 'Box', 'precision' => 0]],
        ], $this->units);

        $categories = [];
        $this->seedRows('product_categories', 'name', [
            ['Gas LPG Demo', ['is_active' => true]],
            ['Perlengkapan Demo', ['is_active' => true]],
        ], $categories);

        $this->seedRows('warehouses', 'code', [
            ['WH-DMY-01', ['name' => 'Gudang Utama Demo', 'address' => 'Jakarta', 'is_default' => true]],
            ['WH-DMY-02', ['name' => 'Gudang Cabang Demo', 'address' => 'Bandung', 'is_default' => false]],
        ], $this->warehouses);

        $this->seedRows('departments', 'code', [
            ['DEMO-SLS', ['name' => 'Sales']],
            ['DEMO-OPS', ['name' => 'Operations']],
            ['DEMO-FIN', ['name' => 'Finance']],
            ['DEMO-WHS', ['name' => 'Warehouse']],
        ], $this->departments);

        $this->seedRows('projects', 'code', [
            ['PRJ-DMY-01', ['name' => 'Distribusi Jakarta', 'start_date' => $this->date('01'), 'status' => 'active']],
            ['PRJ-DMY-02', ['name' => 'Distribusi Bandung', 'start_date' => $this->date('01'), 'status' => 'active']],
            ['PRJ-DMY-03', ['name' => 'Internal Operation', 'start_date' => $this->date('01'), 'status' => 'active']],
        ], $this->projects);

        if ($this->has('products')) {
            $products = [
                ['PRD-DMY-001', 'LPG 3 Kg Demo', $categories['Gas LPG Demo'] ?? null, $this->units['TAB'] ?? null, 18000, 24000],
                ['PRD-DMY-002', 'LPG 12 Kg Demo', $categories['Gas LPG Demo'] ?? null, $this->units['TAB'] ?? null, 120000, 155000],
                ['PRD-DMY-003', 'Regulator Gas Demo', $categories['Perlengkapan Demo'] ?? null, $this->units['PCS'] ?? null, 45000, 70000],
                ['PRD-DMY-004', 'Selang Gas Demo', $categories['Perlengkapan Demo'] ?? null, $this->units['PCS'] ?? null, 25000, 40000],
                ['PRD-DMY-005', 'Seal Tabung Demo', $categories['Perlengkapan Demo'] ?? null, $this->units['BOX'] ?? null, 15000, 25000],
            ];
            foreach ($products as [$code, $name, $category, $unit, $purchasePrice, $salesPrice]) {
                $this->products[$code] = $this->upsert('products', 'product_code', $code, [
                    'product_name' => $name,
                    'product_type' => 'goods',
                    'product_category_id' => $category,
                    'unit_id' => $unit,
                    'is_stock_item' => true,
                    'is_active' => true,
                    'description' => 'Seeded demo stock item.',
                    'sales_account_id' => $this->accounts['4100'],
                    'purchase_account_id' => $this->accounts['5100'],
                    'inventory_account_id' => $this->accounts['1130'],
                    'cogs_account_id' => $this->accounts['5100'],
                    'metadata' => $this->metadata(['purchase_price' => $purchasePrice, 'sales_price' => $salesPrice]),
                ]);
            }
        } else {
            $this->skip('products');
        }

        if ($this->has('account_mappings')) {
            foreach ([
                'sales.revenue' => ['sales', '4100'],
                'sales.accounts_receivable' => ['sales', '1120'],
                'sales.receivable' => ['sales', '1120'],
                'sales.customer_deposit' => ['sales', '2130'],
                'purchase.accounts_payable' => ['purchase', '2100'],
                'purchase.payable' => ['purchase', '2100'],
                'purchase.expense' => ['purchase', '5100'],
                'purchase.inventory_interim' => ['purchase', '2150'],
                'purchase.vendor_deposit' => ['purchase', '1140'],
                'inventory.asset' => ['inventory', '1130'],
                'inventory.cogs' => ['inventory', '5100'],
                'cash.bank' => ['cash_bank', '1110'],
            ] as $key => [$module, $code]) {
                $this->upsert('account_mappings', 'mapping_key', $key, [
                    'module' => $module,
                    'account_id' => $this->accounts[$code],
                    'is_required' => true,
                    'is_active' => true,
                    'metadata' => $this->metadata(),
                ]);
            }
        } else {
            $this->skip('account_mappings');
        }
    }

    /** @return array<string, array{table:string,id:int}> */
    private function seedModuleDocuments(): array
    {
        $refs = [];
        $customer = $this->contacts['CUS-DMY-001'] ?? null;
        $vendor = $this->contacts['SUP-DMY-001'] ?? null;
        $product = $this->products['PRD-DMY-001'] ?? null;
        $unit = $this->units['TAB'] ?? null;
        $warehouse = $this->warehouses['WH-DMY-01'] ?? null;
        $salesDept = $this->departments['DEMO-SLS'] ?? null;
        $opsDept = $this->departments['DEMO-OPS'] ?? null;
        $salesProject = $this->projects['PRJ-DMY-01'] ?? null;
        $opsProject = $this->projects['PRJ-DMY-03'] ?? null;

        $quote = $this->document('sales_quotations', 'quotation_number', $this->number('SQ', 1), [
            'quotation_date' => $this->date('04'), 'valid_until' => $this->date('15'), 'customer_id' => $customer,
            'status' => 'converted', 'subtotal_before_discount' => 35000000, 'subtotal_after_discount' => 35000000, 'grand_total' => 35000000,
            'converted_at' => $this->at('05'), 'notes' => 'Demo converted quotation.',
        ], 'sales_quotation_lines', 'sales_quotation_id', [
            'product_id' => $product, 'product_code' => 'PRD-DMY-001', 'description' => 'LPG delivery quotation',
            'quantity' => 100, 'unit_id' => $unit, 'unit_price' => 350000, 'gross_amount' => 35000000,
            'subtotal_after_discount' => 35000000, 'line_total' => 35000000, 'warehouse_id' => $warehouse,
            'department_id' => $salesDept, 'project_id' => $salesProject,
        ]);
        if ($quote) $refs['quotation'] = ['table' => 'sales_quotations', 'id' => $quote];

        $order = $this->document('sales_orders', 'order_number', $this->number('SO', 1), [
            'order_date' => $this->date('05'), 'customer_id' => $customer, 'quotation_id' => $quote,
            'quotation_number' => $this->number('SQ', 1), 'status' => 'confirmed', 'subtotal_before_discount' => 35000000,
            'subtotal_after_discount' => 35000000, 'grand_total' => 35000000, 'delivered_amount' => 35000000,
            'invoiced_amount' => 35000000, 'confirmed_at' => $this->at('05'), 'notes' => 'Demo confirmed sales order.',
        ], 'sales_order_lines', 'sales_order_id', [
            'product_id' => $product, 'product_code' => 'PRD-DMY-001', 'description' => 'LPG order line',
            'quantity' => 100, 'delivered_quantity' => 100, 'invoiced_quantity' => 100, 'unit_id' => $unit,
            'unit_price' => 350000, 'gross_amount' => 35000000, 'subtotal_after_discount' => 35000000,
            'line_total' => 35000000, 'warehouse_id' => $warehouse, 'department_id' => $salesDept, 'project_id' => $salesProject,
        ]);
        if ($order) $refs['sales_order'] = ['table' => 'sales_orders', 'id' => $order];

        $delivery = $this->document('delivery_orders', 'delivery_number', $this->number('DO', 1), [
            'delivery_date' => $this->date('07'), 'customer_id' => $customer, 'sales_order_id' => $order,
            'sales_order_number' => $this->number('SO', 1), 'warehouse_id' => $warehouse, 'status' => 'delivered',
            'delivered_at' => $this->at('08'), 'notes' => 'Demo delivered order.',
        ], 'delivery_order_lines', 'delivery_order_id', [
            'product_id' => $product, 'product_code' => 'PRD-DMY-001', 'description' => 'Delivered LPG',
            'quantity' => 100, 'invoiced_quantity' => 100, 'unit_id' => $unit, 'warehouse_id' => $warehouse,
            'department_id' => $salesDept, 'project_id' => $salesProject,
        ]);
        if ($delivery) $refs['delivery'] = ['table' => 'delivery_orders', 'id' => $delivery];

        $invoice1 = $this->document('sales_invoices', 'invoice_number', $this->number('SI', 1), [
            'invoice_date' => $this->date('08'), 'due_date' => $this->date('31'), 'customer_id' => $customer,
            'sales_order_id' => $order, 'delivery_order_id' => $delivery, 'status' => 'posted',
            'subtotal_before_discount' => 35000000, 'subtotal_after_discount' => 35000000, 'grand_total' => 35000000,
            'paid_amount' => 30000000, 'returned_amount' => 2000000, 'balance_due' => 3000000, 'posted_at' => $this->at('08'),
        ], 'sales_invoice_lines', 'sales_invoice_id', [
            'product_id' => $product, 'product_code' => 'PRD-DMY-001', 'description' => 'Invoice LPG credit sale',
            'quantity' => 100, 'returned_quantity' => 6, 'unit_id' => $unit, 'unit_price' => 350000,
            'gross_amount' => 35000000, 'subtotal_after_discount' => 35000000, 'line_total' => 35000000,
            'warehouse_id' => $warehouse, 'department_id' => $salesDept, 'project_id' => $salesProject,
        ]);
        if ($invoice1) $refs['sales_invoice_1'] = ['table' => 'sales_invoices', 'id' => $invoice1];

        $invoice2 = $this->document('sales_invoices', 'invoice_number', $this->number('SI', 2), [
            'invoice_date' => $this->date('13'), 'due_date' => $this->date('31'), 'customer_id' => $customer,
            'status' => 'posted', 'subtotal_before_discount' => 20000000, 'subtotal_after_discount' => 20000000,
            'grand_total' => 20000000, 'applied_down_payment_amount' => 8000000, 'balance_due' => 12000000,
            'posted_at' => $this->at('13'),
        ], 'sales_invoice_lines', 'sales_invoice_id', [
            'product_id' => $this->products['PRD-DMY-002'] ?? $product, 'product_code' => 'PRD-DMY-002',
            'description' => 'Invoice LPG 12 Kg', 'quantity' => 20, 'unit_id' => $unit, 'unit_price' => 1000000,
            'gross_amount' => 20000000, 'subtotal_after_discount' => 20000000, 'line_total' => 20000000,
            'warehouse_id' => $warehouse, 'department_id' => $salesDept, 'project_id' => $salesProject,
        ]);
        if ($invoice2) $refs['sales_invoice_2'] = ['table' => 'sales_invoices', 'id' => $invoice2];

        $deposit = $this->document('customer_deposits', 'deposit_number', $this->number('CD', 1), [
            'deposit_date' => $this->date('11'), 'customer_id' => $customer, 'sales_order_id' => $order,
            'cash_bank_account_id' => $this->accounts['1110'], 'amount' => 8000000, 'allocated_amount' => 8000000,
            'remaining_amount' => 0, 'status' => 'posted', 'posted_at' => $this->at('11'),
        ]);
        if ($deposit) $refs['customer_deposit'] = ['table' => 'customer_deposits', 'id' => $deposit];

        $this->document('customer_deposit_allocations', 'id', null, [
            'customer_deposit_id' => $deposit, 'sales_invoice_id' => $invoice2, 'allocation_date' => $this->date('13'),
            'allocated_amount' => 8000000, 'status' => 'posted',
        ], null, null, [], ['customer_deposit_id' => $deposit, 'sales_invoice_id' => $invoice2]);

        $receipt = $this->document('sales_receipts', 'receipt_number', $this->number('SR', 1), [
            'receipt_date' => $this->date('10'), 'customer_id' => $customer, 'sales_invoice_id' => $invoice1,
            'cash_bank_account_id' => $this->accounts['1110'], 'amount' => 15000000, 'status' => 'posted', 'posted_at' => $this->at('10'),
        ], 'sales_receipt_lines', 'sales_receipt_id', [
            'sales_invoice_id' => $invoice1, 'amount' => 15000000, 'description' => 'Partial customer receipt.',
        ]);
        if ($receipt) $refs['sales_receipt'] = ['table' => 'sales_receipts', 'id' => $receipt];

        $return = $this->document('sales_returns', 'return_number', $this->number('SRET', 1), [
            'return_date' => $this->date('18'), 'customer_id' => $customer, 'sales_invoice_id' => $invoice1,
            'delivery_order_id' => $delivery, 'status' => 'posted', 'subtotal_before_discount' => 2000000,
            'grand_total' => 2000000, 'reason' => 'Damaged item correction.', 'posted_at' => $this->at('18'),
        ], 'sales_return_lines', 'sales_return_id', [
            'sales_invoice_line_id' => null, 'product_id' => $product, 'product_code' => 'PRD-DMY-001',
            'description' => 'Returned LPG', 'quantity' => 6, 'unit_id' => $unit, 'unit_price' => 333333.33,
            'line_total' => 2000000, 'warehouse_id' => $warehouse, 'department_id' => $salesDept, 'project_id' => $salesProject,
        ]);
        if ($return) $refs['sales_return'] = ['table' => 'sales_returns', 'id' => $return];

        $purchaseRequest = $this->document('purchase_requests', 'request_number', $this->number('PR', 1), [
            'request_date' => $this->date('02'), 'needed_date' => $this->date('03'), 'department_id' => $opsDept,
            'project_id' => $opsProject, 'status' => 'converted', 'estimated_total' => 25000000, 'approved_at' => $this->at('02'),
            'converted_at' => $this->at('02'),
        ], 'purchase_request_lines', 'purchase_request_id', [
            'product_id' => $product, 'product_code' => 'PRD-DMY-001', 'description' => 'Purchase LPG stock',
            'quantity' => 125, 'unit_id' => $unit, 'estimated_unit_price' => 200000, 'estimated_line_total' => 25000000,
            'warehouse_id' => $warehouse, 'department_id' => $opsDept, 'project_id' => $opsProject,
        ]);
        if ($purchaseRequest) $refs['purchase_request'] = ['table' => 'purchase_requests', 'id' => $purchaseRequest];

        $po = $this->document('purchase_orders', 'order_number', $this->number('PO', 1), [
            'order_date' => $this->date('02'), 'expected_date' => $this->date('03'), 'vendor_id' => $vendor,
            'purchase_request_id' => $purchaseRequest, 'purchase_request_number' => $this->number('PR', 1),
            'status' => 'confirmed', 'subtotal_before_discount' => 25000000, 'subtotal_after_discount' => 25000000,
            'grand_total' => 25000000, 'received_amount' => 25000000, 'billed_amount' => 25000000,
            'confirmed_at' => $this->at('02'),
        ], 'purchase_order_lines', 'purchase_order_id', [
            'product_id' => $product, 'product_code' => 'PRD-DMY-001', 'description' => 'Purchase LPG stock',
            'quantity' => 125, 'received_quantity' => 125, 'billed_quantity' => 125, 'unit_id' => $unit,
            'unit_price' => 200000, 'gross_amount' => 25000000, 'subtotal_after_discount' => 25000000,
            'line_total' => 25000000, 'warehouse_id' => $warehouse, 'department_id' => $opsDept,
            'project_id' => $opsProject, 'expense_account_id' => $this->accounts['1130'],
        ]);
        if ($po) $refs['purchase_order'] = ['table' => 'purchase_orders', 'id' => $po];

        $goods = $this->document('goods_receipts', 'receipt_number', $this->number('GR', 1), [
            'receipt_date' => $this->date('03'), 'vendor_id' => $vendor, 'purchase_order_id' => $po,
            'purchase_order_number' => $this->number('PO', 1), 'warehouse_id' => $warehouse, 'status' => 'received',
            'received_at' => $this->at('03'),
        ], 'goods_receipt_lines', 'goods_receipt_id', [
            'product_id' => $product, 'product_code' => 'PRD-DMY-001', 'description' => 'Goods received LPG',
            'quantity' => 125, 'billed_quantity' => 125, 'unit_id' => $unit, 'warehouse_id' => $warehouse,
            'department_id' => $opsDept, 'project_id' => $opsProject, 'expense_account_id' => $this->accounts['1130'],
        ]);
        if ($goods) $refs['goods_receipt'] = ['table' => 'goods_receipts', 'id' => $goods];

        $bill = $this->document('vendor_bills', 'bill_number', $this->number('BILL', 1), [
            'bill_date' => $this->date('03'), 'due_date' => $this->date('31'), 'vendor_id' => $vendor,
            'purchase_order_id' => $po, 'goods_receipt_id' => $goods, 'status' => 'posted',
            'subtotal_before_discount' => 25000000, 'subtotal_after_discount' => 25000000, 'grand_total' => 25000000,
            'paid_amount' => 18000000, 'returned_amount' => 3000000, 'balance_due' => 4000000, 'posted_at' => $this->at('03'),
        ], 'vendor_bill_lines', 'vendor_bill_id', [
            'product_id' => $product, 'product_code' => 'PRD-DMY-001', 'description' => 'Vendor bill LPG stock',
            'quantity' => 125, 'returned_quantity' => 15, 'unit_id' => $unit, 'unit_price' => 200000,
            'gross_amount' => 25000000, 'subtotal_after_discount' => 25000000, 'line_total' => 25000000,
            'warehouse_id' => $warehouse, 'department_id' => $opsDept, 'project_id' => $opsProject,
            'expense_account_id' => $this->accounts['1130'],
        ]);
        if ($bill) $refs['vendor_bill'] = ['table' => 'vendor_bills', 'id' => $bill];

        $billTwo = $this->document('vendor_bills', 'bill_number', $this->number('BILL', 2), [
            'bill_date' => $this->date('22'), 'due_date' => $this->date('31'), 'vendor_id' => $vendor,
            'vendor_invoice_number' => 'VENDOR-DEMO-OFFICE-001', 'status' => 'posted',
            'subtotal_before_discount' => 1000000, 'subtotal_after_discount' => 1000000, 'grand_total' => 1000000,
            'balance_due' => 1000000, 'posted_at' => $this->at('22'), 'notes' => 'Office supplies bill left outstanding.',
        ], 'vendor_bill_lines', 'vendor_bill_id', [
            'description' => 'Office supplies expense', 'quantity' => 1, 'unit_price' => 1000000,
            'gross_amount' => 1000000, 'subtotal_after_discount' => 1000000, 'line_total' => 1000000,
            'department_id' => $opsDept, 'project_id' => $opsProject, 'expense_account_id' => $this->accounts['6160'],
        ]);
        if ($billTwo) $refs['vendor_bill_2'] = ['table' => 'vendor_bills', 'id' => $billTwo];

        $vendorDeposit = $this->document('vendor_deposits', 'deposit_number', $this->number('VD', 1), [
            'deposit_date' => $this->date('06'), 'vendor_id' => $vendor, 'purchase_order_id' => $po,
            'cash_bank_account_id' => $this->accounts['1110'], 'amount' => 5000000, 'remaining_amount' => 5000000,
            'status' => 'posted', 'posted_at' => $this->at('06'),
        ]);
        if ($vendorDeposit) $refs['vendor_deposit'] = ['table' => 'vendor_deposits', 'id' => $vendorDeposit];

        $payment = $this->document('vendor_payments', 'payment_number', $this->number('VP', 1), [
            'payment_date' => $this->date('05'), 'vendor_id' => $vendor, 'vendor_bill_id' => $bill,
            'cash_bank_account_id' => $this->accounts['1110'], 'amount' => 10000000, 'status' => 'posted', 'posted_at' => $this->at('05'),
        ], 'vendor_payment_lines', 'vendor_payment_id', [
            'vendor_bill_id' => $bill, 'amount' => 10000000, 'description' => 'Partial payment vendor bill.',
        ]);
        if ($payment) $refs['vendor_payment'] = ['table' => 'vendor_payments', 'id' => $payment];

        $returnPurchase = $this->document('purchase_returns', 'return_number', $this->number('PRET', 1), [
            'return_date' => $this->date('20'), 'vendor_id' => $vendor, 'vendor_bill_id' => $bill, 'goods_receipt_id' => $goods,
            'status' => 'posted', 'subtotal_before_discount' => 3000000, 'grand_total' => 3000000,
            'reason' => 'Returned damaged LPG stock.', 'posted_at' => $this->at('20'),
        ], 'purchase_return_lines', 'purchase_return_id', [
            'product_id' => $product, 'product_code' => 'PRD-DMY-001', 'description' => 'Purchase return LPG',
            'quantity' => 15, 'unit_id' => $unit, 'unit_price' => 200000, 'line_total' => 3000000,
            'warehouse_id' => $warehouse, 'department_id' => $opsDept, 'project_id' => $opsProject,
            'expense_account_id' => $this->accounts['1130'],
        ]);
        if ($returnPurchase) $refs['purchase_return'] = ['table' => 'purchase_returns', 'id' => $returnPurchase];

        $this->seedCashBankDocuments($refs);
        $this->seedInventoryDocuments($refs);

        return $refs;
    }

    /** @param array<string, array{table:string,id:int}> $refs */
    private function seedCashBankDocuments(array &$refs): void
    {
        $bank = $this->accounts['1110'];
        $cash = $this->accounts['1100'];
        $customer = $this->contacts['CUS-DMY-001'] ?? null;
        $vendor = $this->contacts['SUP-DMY-001'] ?? null;

        $receipt = $this->document('cash_receipts', 'receipt_number', $this->number('CR', 1), [
            'receipt_date' => $this->date('01'), 'cash_bank_account_id' => $bank, 'amount' => 100000000,
            'status' => 'posted', 'posted_at' => $this->at('01'), 'notes' => 'Opening owner capital.',
        ], 'cash_receipt_lines', 'cash_receipt_id', [
            'account_id' => $this->accounts['3100'], 'amount' => 100000000, 'description' => 'Owner capital',
        ]);
        if ($receipt) $refs['cash_receipt_opening'] = ['table' => 'cash_receipts', 'id' => $receipt];

        $receiptCustomer = $this->document('cash_receipts', 'receipt_number', $this->number('CR', 2), [
            'receipt_date' => $this->date('24'), 'cash_bank_account_id' => $bank, 'contact_id' => $customer,
            'amount' => 25000000, 'status' => 'posted', 'posted_at' => $this->at('24'), 'notes' => 'AR settlement receipt.',
        ], 'cash_receipt_lines', 'cash_receipt_id', [
            'account_id' => $this->accounts['1120'], 'amount' => 25000000, 'description' => 'Customer receivable settlement',
        ]);
        if ($receiptCustomer) $refs['cash_receipt_ar'] = ['table' => 'cash_receipts', 'id' => $receiptCustomer];

        $payment = $this->document('cash_payments', 'payment_number', $this->number('CP', 1), [
            'payment_date' => $this->date('26'), 'cash_bank_account_id' => $bank, 'contact_id' => $vendor,
            'amount' => 8000000, 'status' => 'posted', 'posted_at' => $this->at('26'), 'notes' => 'AP settlement payment.',
        ], 'cash_payment_lines', 'cash_payment_id', [
            'account_id' => $this->accounts['2100'], 'amount' => 8000000, 'description' => 'Vendor payable settlement',
        ]);
        if ($payment) $refs['cash_payment_ap'] = ['table' => 'cash_payments', 'id' => $payment];

        $transfer = $this->document('bank_transfers', 'transfer_number', $this->number('BT', 1), [
            'transfer_date' => $this->date('14'), 'from_cash_bank_account_id' => $bank, 'to_cash_bank_account_id' => $cash,
            'amount' => 2000000, 'status' => 'posted', 'posted_at' => $this->at('14'), 'notes' => 'Petty cash replenishment.',
        ]);
        if ($transfer) $refs['bank_transfer'] = ['table' => 'bank_transfers', 'id' => $transfer];

        $recon = $this->document('bank_reconciliations', 'reconciliation_number', $this->number('BR', 1), [
            'cash_bank_account_id' => $bank, 'statement_start_date' => $this->date('01'), 'statement_end_date' => $this->date('31'),
            'statement_opening_balance' => 0, 'statement_ending_balance' => 88850000, 'status' => 'draft',
            'notes' => 'January demo bank reconciliation sample.',
        ]);
        if ($recon) $refs['bank_reconciliation'] = ['table' => 'bank_reconciliations', 'id' => $recon];
    }

    /** @param array<string, array{table:string,id:int}> $refs */
    private function seedInventoryDocuments(array &$refs): void
    {
        $product = $this->products['PRD-DMY-001'] ?? null;
        $warehouse = $this->warehouses['WH-DMY-01'] ?? null;
        $unit = $this->units['TAB'] ?? null;
        if (! $product || ! $warehouse) return;

        foreach ([
            ['SM-DMY-OPEN', '01', 'opening', 'in', 50, 200000, null],
            ['SM-DMY-GR', '03', 'goods_receipt', 'in', 125, 200000, $refs['goods_receipt']['id'] ?? null],
            ['SM-DMY-DO', '08', 'delivery_order', 'out', 100, 200000, $refs['delivery']['id'] ?? null],
            ['SM-DMY-SRET', '18', 'sales_return', 'in', 6, 200000, $refs['sales_return']['id'] ?? null],
            ['SM-DMY-PRET', '20', 'purchase_return', 'out', 15, 200000, $refs['purchase_return']['id'] ?? null],
            ['SM-DMY-ADJ', '31', 'adjustment', 'out', 1, 200000, null],
        ] as [$no, $day, $type, $direction, $qty, $cost, $sourceId]) {
            $movement = $this->document('stock_movements', 'movement_number', $no.'-'.$this->period, [
                'movement_date' => $this->date($day), 'movement_type' => $type, 'direction' => $direction, 'status' => 'posted',
                'source_type' => $type, 'source_id' => $sourceId, 'warehouse_id' => $warehouse,
                'description' => 'Demo '.$type.' stock movement.', 'total_quantity' => $qty, 'total_value' => $qty * $cost,
                'posted_at' => $this->at($day),
            ], 'stock_movement_lines', 'stock_movement_id', [
                'movement_type' => $type, 'direction' => $direction, 'product_id' => $product, 'product_code' => 'PRD-DMY-001',
                'warehouse_id' => $warehouse, 'unit_id' => $unit, 'quantity' => $qty, 'unit_cost' => $cost,
                'total_cost' => $qty * $cost,
            ]);
            if ($movement) $refs[$no] = ['table' => 'stock_movements', 'id' => $movement];
        }

        $adjustment = $this->document('stock_adjustments', 'adjustment_number', $this->number('SA', 1), [
            'adjustment_date' => $this->date('31'), 'warehouse_id' => $warehouse, 'status' => 'posted',
            'reason' => 'Month-end damaged stock adjustment.', 'stock_movement_id' => $refs['SM-DMY-ADJ']['id'] ?? null,
            'approved_at' => $this->at('31'), 'posted_at' => $this->at('31'),
        ], 'stock_adjustment_lines', 'stock_adjustment_id', [
            'product_id' => $product, 'warehouse_id' => $warehouse, 'unit_id' => $unit, 'adjustment_type' => 'decrease',
            'quantity' => 1, 'unit_cost' => 200000, 'total_cost' => 200000, 'reason' => 'Damaged cylinder.',
        ]);
        if ($adjustment) $refs['stock_adjustment'] = ['table' => 'stock_adjustments', 'id' => $adjustment];

        $opname = $this->document('stock_opnames', 'opname_number', $this->number('OPN', 1), [
            'opname_date' => $this->date('31'), 'warehouse_id' => $warehouse, 'status' => 'finalized',
            'counted_at' => $this->at('31'), 'finalized_at' => $this->at('31'), 'notes' => 'Month-end demo count.',
        ], 'stock_opname_lines', 'stock_opname_id', [
            'product_id' => $product, 'warehouse_id' => $warehouse, 'unit_id' => $unit, 'system_quantity' => 65,
            'physical_quantity' => 65, 'difference_quantity' => 0, 'average_cost' => 200000, 'difference_value' => 0,
            'counted_at' => $this->at('31'),
        ]);
        if ($opname) $refs['stock_opname'] = ['table' => 'stock_opnames', 'id' => $opname];
    }

    private function seedJournalCycle(): void
    {
        $entries = [
            [1, '01', 'Opening capital paid into operating bank', [['1110', 100000000, 0], ['3100', 0, 100000000]]],
            [2, '03', 'Inventory purchased on credit', [['1130', 25000000, 0], ['2100', 0, 25000000]]],
            [3, '05', 'Partial vendor payment', [['2100', 10000000, 0], ['1110', 0, 10000000]]],
            [4, '06', 'Vendor down payment', [['1140', 5000000, 0], ['1110', 0, 5000000]]],
            [5, '08', 'Credit sale invoice SI-001', [['1120', 35000000, 0], ['4100', 0, 35000000]]],
            [6, '08', 'Cost of goods sold for SI-001', [['5100', 20000000, 0], ['1130', 0, 20000000]]],
            [7, '10', 'Partial customer receipt', [['1110', 15000000, 0], ['1120', 0, 15000000]]],
            [8, '11', 'Customer deposit received', [['1110', 8000000, 0], ['2130', 0, 8000000]]],
            [9, '13', 'Credit sale invoice SI-002', [['1120', 20000000, 0], ['4100', 0, 20000000]]],
            [10, '13', 'Cost of goods sold for SI-002', [['5100', 12000000, 0], ['1130', 0, 12000000]]],
            [11, '13', 'Apply customer deposit to invoice', [['2130', 8000000, 0], ['1120', 0, 8000000]]],
            [12, '14', 'Transfer bank funds to petty cash', [['1100', 2000000, 0], ['1110', 0, 2000000]]],
            [13, '15', 'Electricity expense', [['6100', 1000000, 0], ['1110', 0, 1000000]]],
            [14, '16', 'Internet expense', [['6110', 600000, 0], ['1110', 0, 600000]]],
            [15, '17', 'Salary expense', [['6120', 6000000, 0], ['1110', 0, 6000000]]],
            [16, '18', 'Sales return adjustment', [['4110', 2000000, 0], ['1120', 0, 2000000]]],
            [17, '18', 'Inventory restored from sales return', [['1130', 1200000, 0], ['5100', 0, 1200000]]],
            [18, '20', 'Purchase return against vendor payable', [['2100', 3000000, 0], ['1130', 0, 3000000]]],
            [19, '21', 'Transport expense paid in cash', [['6130', 750000, 0], ['1100', 0, 750000]]],
            [20, '22', 'Bank administration fee', [['6140', 150000, 0], ['1110', 0, 150000]]],
            [21, '23', 'Cash retail sale', [['1100', 4000000, 0], ['4100', 0, 4000000]]],
            [22, '23', 'COGS for cash retail sale', [['5100', 2200000, 0], ['1130', 0, 2200000]]],
            [23, '24', 'Customer receivable settlement leaving outstanding history', [['1110', 25000000, 0], ['1120', 0, 25000000]]],
            [24, '26', 'Vendor payable settlement leaving outstanding balance', [['2100', 8000000, 0], ['1110', 0, 8000000]]],
            [25, '31', 'Month-end accrued supplies adjustment', [['6160', 500000, 0], ['2110', 0, 500000]]],
            [26, '31', 'Accrued operating expense adjustment', [['6160', 400000, 0], ['2110', 0, 400000]]],
            [27, '22', 'Office supplies vendor bill left payable', [['6160', 1000000, 0], ['2100', 0, 1000000]]],
        ];

        if (! $this->has('journal_entries') || ! $this->has('journal_entry_lines')) {
            throw new RuntimeException('journal_entries and journal_entry_lines tables are required.');
        }

        foreach ($entries as [$sequence, $day, $description, $lines]) {
            $number = $this->number('JRN', $sequence);
            $debit = array_sum(array_map(fn (array $line): float => (float) $line[1], $lines));
            $credit = array_sum(array_map(fn (array $line): float => (float) $line[2], $lines));
            if (abs($debit - $credit) >= 0.01) {
                throw new RuntimeException("Unbalanced dummy journal {$number}: debit {$debit}, credit {$credit}.");
            }

            $journalId = $this->upsert('journal_entries', 'journal_number', $number, [
                'journal_date' => $this->date($day),
                'description' => $description,
                'status' => 'posted',
                'revision_no' => 1,
                'source_type' => 'tenant_dummy_seed',
                'source_number' => $number,
                'source_revision' => 1,
                'source_module' => 'dummy_cycle',
                'is_system_generated' => true,
                'is_obsolete' => false,
                'posted_at' => $this->at($day),
                'metadata' => $this->metadata(['total_debit' => $debit, 'total_credit' => $credit]),
            ]);
            $this->journals[$number] = $journalId;
            DB::connection('tenant')->table('journal_entry_lines')->where('journal_entry_id', $journalId)->delete();
            foreach ($lines as $order => [$accountCode, $lineDebit, $lineCredit]) {
                DB::connection('tenant')->table('journal_entry_lines')->insert($this->filter('journal_entry_lines', [
                    'journal_entry_id' => $journalId,
                    'account_id' => $this->accounts[$accountCode],
                    'description' => $description,
                    'debit' => $lineDebit,
                    'credit' => $lineCredit,
                    'line_order' => $order + 1,
                    'department_id' => $this->departments['DEMO-FIN'] ?? null,
                    'project_id' => $this->projects['PRJ-DMY-03'] ?? null,
                    'metadata' => $this->metadata(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    /** @param array<string, array{table:string,id:int}> $documents */
    private function linkDocumentJournals(array $documents): void
    {
        foreach ([
            'sales_invoice_1' => 5, 'sales_invoice_2' => 9, 'customer_deposit' => 8, 'sales_receipt' => 7,
            'sales_return' => 16, 'vendor_bill' => 2, 'vendor_deposit' => 4, 'vendor_payment' => 3,
            'purchase_return' => 18, 'vendor_bill_2' => 27, 'cash_receipt_opening' => 1, 'cash_receipt_ar' => 23,
            'cash_payment_ap' => 24, 'bank_transfer' => 12,
        ] as $document => $journalSequence) {
            if (! isset($documents[$document])) continue;
            $journalId = $this->journals[$this->number('JRN', $journalSequence)] ?? null;
            if (! $journalId) continue;
            $table = $documents[$document]['table'];
            if (! Schema::connection('tenant')->hasColumn($table, 'journal_entry_id')) continue;
            DB::connection('tenant')->table($table)->where('id', $documents[$document]['id'])->update(['journal_entry_id' => $journalId]);
        }
    }

    private function seedInventoryBalances(): void
    {
        if (! $this->has('stock_balances') || ! isset($this->products['PRD-DMY-001'], $this->warehouses['WH-DMY-01'])) return;
        $lastMovementId = $this->has('stock_movements')
            ? (int) DB::connection('tenant')->table('stock_movements')->where('movement_number', 'like', 'SM-DMY-%-'.$this->period)->max('id')
            : null;
        $this->upsertComposite('stock_balances', [
            'product_id' => $this->products['PRD-DMY-001'],
            'warehouse_id' => $this->warehouses['WH-DMY-01'],
        ], [
            'quantity_on_hand' => 65,
            'quantity_reserved' => 0,
            'quantity_available' => 65,
            'average_cost' => 200000,
            'total_value' => 13000000,
            'last_movement_id' => $lastMovementId,
            'last_movement_at' => $this->at('31'),
            'metadata' => $this->metadata(),
        ]);
    }

    private function seedBankReconciliationLines(): void
    {
        if (! $this->has('bank_reconciliation_lines') || ! $this->has('bank_reconciliations')) return;
        $reconciliationId = DB::connection('tenant')->table('bank_reconciliations')
            ->where('reconciliation_number', $this->number('BR', 1))->value('id');
        if (! $reconciliationId) return;
        DB::connection('tenant')->table('bank_reconciliation_lines')->where('bank_reconciliation_id', $reconciliationId)->delete();
        foreach ([$this->number('JRN', 1), $this->number('JRN', 3), $this->number('JRN', 23)] as $order => $number) {
            $journalId = $this->journals[$number] ?? null;
            if (! $journalId) continue;
            $line = DB::connection('tenant')->table('journal_entry_lines')->where('journal_entry_id', $journalId)
                ->where('account_id', $this->accounts['1110'])->first();
            if (! $line) continue;
            DB::connection('tenant')->table('bank_reconciliation_lines')->insert($this->filter('bank_reconciliation_lines', [
                'bank_reconciliation_id' => $reconciliationId, 'journal_entry_id' => $journalId, 'journal_entry_line_id' => $line->id,
                'journal_date' => DB::connection('tenant')->table('journal_entries')->where('id', $journalId)->value('journal_date'),
                'journal_number' => $number, 'description' => 'Cleared demo bank transaction',
                'debit' => $line->debit, 'credit' => $line->credit, 'is_cleared' => true,
                'cleared_date' => $this->date('31'), 'line_order' => $order + 1, 'metadata' => $this->metadata(),
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }

    /**
     * @param array<int, array{0:string,1:array<string,mixed>}> $rows
     * @param array<string, int> $ids
     */
    private function seedRows(string $table, string $key, array $rows, array &$ids): void
    {
        if (! $this->has($table)) return;
        foreach ($rows as [$value, $data]) {
            $ids[$value] = $this->upsert($table, $key, $value, array_merge(['is_active' => true, 'metadata' => $this->metadata()], $data));
        }
    }

    private function document(
        string $table,
        string $key,
        string|int|null $value,
        array $header,
        ?string $lineTable = null,
        ?string $foreignKey = null,
        array $line = [],
        array $composite = [],
    ): ?int {
        if (! $this->has($table)) return null;
        $payload = array_merge($header, ['metadata' => $this->metadata()]);
        $id = $composite !== []
            ? $this->upsertComposite($table, $composite, $payload)
            : $this->upsert($table, $key, $value, $payload);
        if ($lineTable && $foreignKey && $this->has($lineTable)) {
            DB::connection('tenant')->table($lineTable)->where($foreignKey, $id)->delete();
            DB::connection('tenant')->table($lineTable)->insert($this->filter($lineTable, array_merge($line, [
                $foreignKey => $id, 'sort_order' => 1, 'metadata' => $this->metadata(), 'created_at' => now(), 'updated_at' => now(),
            ])));
        }
        return $id;
    }

    private function upsert(string $table, string $key, string|int|null $value, array $payload): int
    {
        $match = [$key => $value];
        return $this->upsertComposite($table, $match, $payload);
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
        $insert = $this->filter($table, array_merge($match, $payload, ['created_at' => now()]));
        return (int) DB::connection('tenant')->table($table)->insertGetId($insert);
    }

    private function has(string $table): bool
    {
        if (Schema::connection('tenant')->hasTable($table)) {
            $this->seededTables[] = $table;
            return true;
        }
        $this->skip($table);
        return false;
    }

    private function skip(string $table): void
    {
        $this->skippedTables[] = $table;
    }

    private function filter(string $table, array $values): array
    {
        return array_filter($values, fn (string $column): bool => Schema::connection('tenant')->hasColumn($table, $column), ARRAY_FILTER_USE_KEY);
    }

    private function metadata(array $additional = []): string
    {
        return json_encode(array_merge(['seeded_by' => self::SEEDED_BY, 'period' => $this->period], $additional), JSON_THROW_ON_ERROR);
    }

    private function number(string $prefix, int $sequence): string
    {
        return sprintf('%s-DMY-%s-%03d', $prefix, $this->period, $sequence);
    }

    private function prefix(string $prefix): string
    {
        return sprintf('%s-DMY-%s-', $prefix, $this->period);
    }

    private function date(string $day): string
    {
        return $this->period.'-'.$day;
    }

    private function at(string $day): string
    {
        return Carbon::parse($this->date($day).' 12:00:00')->toDateTimeString();
    }
}
