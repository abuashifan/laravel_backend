<?php

namespace App\Console\Commands;

use App\Models\Tenant\StockMovement;
use App\Models\Tenant\VendorBill;
use App\Models\TenantDatabase;
use App\Services\Purchase\PurchaseAccountResolverService;
use App\Services\Tenant\TenantConnectionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class BackfillVendorBillAccountSnapshotsCommand extends Command
{
    protected $signature = 'tenant:backfill-vendor-bill-account-snapshots
        {--company-id= : Backfill one active tenant by company_id}
        {--all : Backfill all active tenants}
        {--execute : Persist changes. Without this option the command runs as dry-run}';

    protected $description = 'Backfill vendor bill AP, purchase expense, and inventory account snapshots without rewriting historical journals';

    public function handle(TenantConnectionManager $connections, PurchaseAccountResolverService $resolver): int
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

        $totals = ['bills' => 0, 'bill_lines' => 0, 'stock_lines' => 0, 'errors' => 0];
        $this->line($execute ? 'Mode: execute' : 'Mode: dry-run');

        foreach ($tenants as $tenant) {
            try {
                $connections->connect($tenant);
                $result = $this->backfillTenant($resolver, $execute);
                foreach ($totals as $key => $value) {
                    $totals[$key] += $result[$key];
                }
                $this->line(sprintf(
                    '[OK] company_id=%d bills=%d bill_lines=%d stock_lines=%d errors=%d',
                    $tenant->company_id,
                    $result['bills'],
                    $result['bill_lines'],
                    $result['stock_lines'],
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
            'Total bills=%d bill_lines=%d stock_lines=%d errors=%d',
            $totals['bills'],
            $totals['bill_lines'],
            $totals['stock_lines'],
            $totals['errors'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{bills:int,bill_lines:int,stock_lines:int,errors:int}
     */
    private function backfillTenant(PurchaseAccountResolverService $resolver, bool $execute): array
    {
        foreach (['vendor_bills', 'vendor_bill_lines', 'stock_movements', 'stock_movement_lines', 'contacts', 'chart_of_accounts'] as $table) {
            if (! Schema::connection('tenant')->hasTable($table)) {
                return ['bills' => 0, 'bill_lines' => 0, 'stock_lines' => 0, 'errors' => 1];
            }
        }
        if (! Schema::connection('tenant')->hasColumn('vendor_bills', 'ap_account_id')
            || ! Schema::connection('tenant')->hasColumn('stock_movement_lines', 'inventory_account_id')) {
            return ['bills' => 0, 'bill_lines' => 0, 'stock_lines' => 0, 'errors' => 1];
        }

        $result = ['bills' => 0, 'bill_lines' => 0, 'stock_lines' => 0, 'errors' => 0];

        VendorBill::query()
            ->with('vendor', 'lines.product')
            ->whereNotIn('status', ['draft', 'approved', 'void'])
            ->whereNotNull('posted_at')
            ->orderBy('id')
            ->chunkById(100, function ($bills) use ($resolver, $execute, &$result): void {
                foreach ($bills as $bill) {
                    try {
                        if (! $bill->ap_account_id) {
                            $accountId = $resolver->getPayableAccountId($bill->vendor);
                            $result['bills']++;
                            if ($execute) {
                                $bill->ap_account_id = $accountId;
                                $bill->save();
                            }
                        }

                        foreach ($bill->lines as $line) {
                            if ($line->expense_account_id || $resolver->lineIsStockItem($line)) {
                                continue;
                            }
                            $accountId = $resolver->getPurchaseExpenseAccountIdForLine($line);
                            $result['bill_lines']++;
                            if ($execute) {
                                $line->expense_account_id = $accountId;
                                $line->save();
                            }
                        }
                    } catch (Throwable) {
                        $result['errors']++;
                    }
                }
            });

        StockMovement::query()
            ->with('lines.product')
            ->whereIn('movement_type', ['purchase_in', 'purchase_return_out'])
            ->whereIn('status', ['posted', 'draft'])
            ->orderBy('id')
            ->chunkById(100, function ($movements) use ($resolver, $execute, &$result): void {
                foreach ($movements as $movement) {
                    foreach ($movement->lines as $line) {
                        try {
                            if ($line->inventory_account_id) {
                                continue;
                            }
                            $accountId = $resolver->getInventoryAccountIdForLine(['product_id' => $line->product_id]);
                            $result['stock_lines']++;
                            if ($execute) {
                                $line->inventory_account_id = $accountId;
                                $line->save();
                            }
                        } catch (Throwable) {
                            $result['errors']++;
                        }
                    }
                }
            });

        return $result;
    }
}
