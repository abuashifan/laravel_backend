<?php

namespace App\Console\Commands;

use App\Models\Tenant\SalesInvoice;
use App\Models\TenantDatabase;
use App\Services\Sales\SalesAccountResolverService;
use App\Services\Tenant\TenantConnectionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class BackfillSalesInvoiceAccountSnapshotsCommand extends Command
{
    protected $signature = 'tenant:backfill-sales-invoice-account-snapshots
        {--company-id= : Backfill one active tenant by company_id}
        {--all : Backfill all active tenants}
        {--execute : Persist changes. Without this option the command runs as dry-run}';

    protected $description = 'Backfill sales invoice AR and revenue account snapshots without rewriting historical journals';

    public function handle(TenantConnectionManager $connections, SalesAccountResolverService $resolver): int
    {
        $companyId = $this->option('company-id');
        $all = (bool) $this->option('all');
        $execute = (bool) $this->option('execute');

        if (($companyId === null || $companyId === '') && ! $all) {
            $this->error('Gunakan --company-id=ID atau --all.');
            return self::FAILURE;
        }
        if ($companyId !== null && $companyId !== '' && $all) {
            $this->error('Tidak boleh memakai --company-id dan --all bersamaan.');
            return self::FAILURE;
        }

        $tenants = TenantDatabase::query()
            ->where('status', 'active')
            ->when($companyId !== null && $companyId !== '', fn ($query) => $query->where('company_id', (int) $companyId))
            ->orderBy('company_id')
            ->get();

        if ($tenants->isEmpty()) {
            $this->error('Tidak ada tenant aktif yang cocok.');
            return self::FAILURE;
        }

        $totals = ['invoices' => 0, 'lines' => 0, 'errors' => 0];
        $this->line($execute ? 'Mode: execute' : 'Mode: dry-run');

        foreach ($tenants as $tenant) {
            try {
                $connections->connect($tenant);
                $result = $this->backfillTenant($resolver, $execute);
                $totals['invoices'] += $result['invoices'];
                $totals['lines'] += $result['lines'];
                $totals['errors'] += $result['errors'];
                $this->line(sprintf(
                    '[OK] company_id=%d invoices=%d lines=%d errors=%d',
                    $tenant->company_id,
                    $result['invoices'],
                    $result['lines'],
                    $result['errors'],
                ));
            } catch (Throwable $e) {
                $totals['errors']++;
                $this->error('[FAILED] company_id='.$tenant->company_id.' '.$e->getMessage());
            } finally {
                $connections->disconnect();
            }
        }

        $this->line(sprintf(
            'Total invoices=%d lines=%d errors=%d',
            $totals['invoices'],
            $totals['lines'],
            $totals['errors'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{invoices:int,lines:int,errors:int}
     */
    private function backfillTenant(SalesAccountResolverService $resolver, bool $execute): array
    {
        foreach (['sales_invoices', 'sales_invoice_lines', 'contacts', 'chart_of_accounts'] as $table) {
            if (! Schema::connection('tenant')->hasTable($table)) {
                return ['invoices' => 0, 'lines' => 0, 'errors' => 1];
            }
        }
        if (! Schema::connection('tenant')->hasColumn('sales_invoices', 'ar_account_id')
            || ! Schema::connection('tenant')->hasColumn('sales_invoice_lines', 'revenue_account_id')) {
            return ['invoices' => 0, 'lines' => 0, 'errors' => 1];
        }

        $result = ['invoices' => 0, 'lines' => 0, 'errors' => 0];
        SalesInvoice::query()
            ->with('customer', 'lines')
            ->whereNotIn('status', ['draft', 'approved', 'void'])
            ->whereNotNull('posted_at')
            ->orderBy('id')
            ->chunkById(100, function ($invoices) use ($resolver, $execute, &$result): void {
                foreach ($invoices as $invoice) {
                    try {
                        if (! $invoice->ar_account_id) {
                            $accountId = $resolver->getReceivableAccountId($invoice->customer);
                            $result['invoices']++;
                            if ($execute) {
                                $invoice->ar_account_id = $accountId;
                                $invoice->save();
                            }
                        }

                        foreach ($invoice->lines as $line) {
                            if ($line->revenue_account_id) {
                                continue;
                            }
                            $accountId = $resolver->getRevenueAccountIdForLine($line);
                            $result['lines']++;
                            if ($execute) {
                                $line->revenue_account_id = $accountId;
                                $line->save();
                            }
                        }
                    } catch (Throwable) {
                        $result['errors']++;
                    }
                }
            });

        return $result;
    }
}
