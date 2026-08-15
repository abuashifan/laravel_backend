<?php

namespace Tests\Feature\Sales;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Project;
use App\Shared\Models\CompanyAccountingSetting;
use Illuminate\Support\Collection;

/**
 * Fase 4 — dimensi di baris jurnal pendapatan (G11).
 *
 * `sales_invoice_lines` sudah lama membawa `department_id`/`project_id`, dan
 * dimensi itu diteruskan rapi dari quotation → order → delivery order →
 * invoice. Yang hilang cuma langkah terakhir: saat baris jurnal dibentuk,
 * pengelompokannya hanya per akun sehingga dimensinya dibuang. Tanpa perbaikan
 * ini Actual Revenue per proyek selalu 0 dan Project Profitability mustahil.
 */
class SalesInvoiceDimensionTest extends SalesTestCase
{
    private function bootPostingTenant(): array
    {
        $ctx = $this->setUpTenant();
        $this->seedPostingMappings();

        CompanyAccountingSetting::query()->where('company_id', $ctx['company']->id)->update([
            'transaction_workflow_mode' => 'simple_auto_post',
            'auto_post_transactions' => true,
            'approval_enabled' => false,
        ]);

        return $ctx;
    }

    private function revenueLines(array $invoice): Collection
    {
        $journal = JournalEntry::query()
            ->where('source_type', 'sales_invoice')
            ->where('source_id', $invoice['id'])
            ->with('lines')
            ->firstOrFail();

        return $journal->lines->where('description', 'Sales Revenue')->values();
    }

    private function assertBalanced(array $invoice): void
    {
        $journal = JournalEntry::query()
            ->where('source_type', 'sales_invoice')
            ->where('source_id', $invoice['id'])
            ->with('lines')
            ->firstOrFail();

        $this->assertEqualsWithDelta(
            (float) $journal->lines->sum('debit'),
            (float) $journal->lines->sum('credit'),
            0.005,
            'Jurnal invoice harus tetap balance setelah dimensi ikut dikelompokkan.',
        );
    }

    public function test_revenue_line_carries_project_and_department(): void
    {
        $ctx = $this->bootPostingTenant();
        $project = $this->project('PRJ-1', 'Renovasi Kantor');
        $department = $this->department('OPS', 'Operational');

        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload([
            'lines' => [[
                'description' => 'Jasa renovasi',
                'quantity' => 1,
                'unit_price' => 1_000,
                'tax_rate' => 0,
                'department_id' => $department->id,
                'project_id' => $project->id,
            ]],
        ]), $ctx['headers'])->assertStatus(201)->json('data');

        $revenueLines = $this->revenueLines($invoice);

        $this->assertCount(1, $revenueLines);
        $this->assertSame($project->id, $revenueLines[0]->project_id);
        $this->assertSame($department->id, $revenueLines[0]->department_id);
        $this->assertBalanced($invoice);
    }

    public function test_same_revenue_account_across_two_projects_produces_two_journal_lines(): void
    {
        $ctx = $this->bootPostingTenant();
        $renovation = $this->project('PRJ-1', 'Renovasi Kantor');
        $campaign = $this->project('PRJ-2', 'Campaign Q3');

        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload([
            'lines' => [
                ['description' => 'Jasa renovasi', 'quantity' => 1, 'unit_price' => 1_000, 'tax_rate' => 0, 'project_id' => $renovation->id],
                ['description' => 'Jasa campaign', 'quantity' => 1, 'unit_price' => 400, 'tax_rate' => 0, 'project_id' => $campaign->id],
            ],
        ]), $ctx['headers'])->assertStatus(201)->json('data');

        $revenueLines = $this->revenueLines($invoice);

        // Akun pendapatannya sama, tapi proyeknya beda → dua baris, bukan satu.
        $this->assertCount(2, $revenueLines);
        $this->assertEqualsCanonicalizing(
            [$renovation->id, $campaign->id],
            $revenueLines->pluck('project_id')->all(),
        );

        // Aritmetikanya tidak berubah — hanya kunci groupingnya.
        $this->assertEqualsWithDelta(1_400.0, (float) $revenueLines->sum('credit'), 0.005);
        $this->assertBalanced($invoice);
    }

    public function test_discount_line_carries_the_dimension_too(): void
    {
        $ctx = $this->bootPostingTenant();
        $project = $this->project('PRJ-1', 'Renovasi Kantor');

        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload([
            'lines' => [[
                'description' => 'Jasa renovasi',
                'quantity' => 1,
                'unit_price' => 1_000,
                'tax_rate' => 0,
                'discount_type' => 'percent',
                'discount_value' => 10,
                'project_id' => $project->id,
            ]],
        ]), $ctx['headers'])->assertStatus(201)->json('data');

        $journal = JournalEntry::query()
            ->where('source_type', 'sales_invoice')
            ->where('source_id', $invoice['id'])
            ->with('lines')
            ->firstOrFail();

        $discountLine = $journal->lines->firstWhere('description', 'Sales Discount');

        $this->assertNotNull($discountLine, 'Baris diskon seharusnya terbentuk.');
        $this->assertSame($project->id, $discountLine->project_id);
        $this->assertBalanced($invoice);
    }

    /**
     * Jaring pengaman utama: invoice yang tidak memakai dimensi harus
     * menghasilkan jurnal yang persis sama seperti sebelum perubahan.
     */
    public function test_invoice_without_dimensions_produces_an_unchanged_journal(): void
    {
        $ctx = $this->bootPostingTenant();

        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload([
            'lines' => [
                ['description' => 'Service A', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 11],
                ['description' => 'Service B', 'quantity' => 1, 'unit_price' => 300, 'tax_rate' => 11],
            ],
        ]), $ctx['headers'])->assertStatus(201)->json('data');

        $revenueLines = $this->revenueLines($invoice);

        // Dua baris invoice, akun pendapatan sama, tanpa dimensi → tetap SATU
        // baris jurnal pendapatan seperti sebelumnya.
        $this->assertCount(1, $revenueLines);
        $this->assertNull($revenueLines[0]->project_id);
        $this->assertNull($revenueLines[0]->department_id);
        $this->assertEqualsWithDelta(500.0, (float) $revenueLines[0]->credit, 0.005);
        $this->assertBalanced($invoice);
    }

    public function test_department_alone_is_enough_to_split_the_revenue_line(): void
    {
        $ctx = $this->bootPostingTenant();
        $ops = $this->department('OPS', 'Operational');
        $mkt = $this->department('MKT', 'Marketing');

        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload([
            'lines' => [
                ['description' => 'Service A', 'quantity' => 1, 'unit_price' => 600, 'tax_rate' => 0, 'department_id' => $ops->id],
                ['description' => 'Service B', 'quantity' => 1, 'unit_price' => 400, 'tax_rate' => 0, 'department_id' => $mkt->id],
            ],
        ]), $ctx['headers'])->assertStatus(201)->json('data');

        $revenueLines = $this->revenueLines($invoice);

        $this->assertCount(2, $revenueLines);
        $this->assertEqualsCanonicalizing(
            [$ops->id, $mkt->id],
            $revenueLines->pluck('department_id')->all(),
        );
        $this->assertBalanced($invoice);
    }

    // ------------------------------------------------------------- helpers

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

    private function project(string $code, string $name): Project
    {
        return Project::query()->create([
            'code' => $code,
            'name' => $name,
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    private function department(string $code, string $name): Department
    {
        return Department::query()->create([
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function seedPostingMappings(): void
    {
        $mappings = [
            'sales.accounts_receivable' => $this->account('1100', 'Accounts Receivable', 'asset', 'debit'),
            'sales.revenue' => $this->account('4100', 'Sales Revenue', 'revenue', 'credit'),
            'sales.tax_output' => $this->account('2100', 'Output Tax', 'liability', 'credit'),
            'sales.customer_deposit' => $this->account('2200', 'Customer Deposit', 'liability', 'credit'),
            'sales.return' => $this->account('4200', 'Sales Return', 'revenue', 'credit'),
            'sales.discount' => $this->account('4300', 'Sales Discount', 'revenue', 'credit'),
        ];

        foreach ($mappings as $key => $accountId) {
            AccountMapping::query()->updateOrCreate(
                ['mapping_key' => $key],
                ['module' => 'sales', 'account_id' => $accountId, 'is_active' => true],
            );
        }
    }

    private function account(string $code, string $name, string $type, string $normal): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $normal,
            'is_cash_bank' => false,
            'is_active' => true,
        ])->id;
    }
}
