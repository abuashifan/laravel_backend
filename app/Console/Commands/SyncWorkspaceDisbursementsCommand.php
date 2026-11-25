<?php

namespace App\Console\Commands;

use App\Modules\Workspace\Services\WorkspaceAccountingHandoff;
use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\TenantConnectionManager;
use Illuminate\Console\Command;
use Throwable;

class SyncWorkspaceDisbursementsCommand extends Command
{
    protected $signature = 'workspace:sync-disbursements {--limit=100 : Maximum pending drafts to try per company}';

    protected $description = 'Send pending Workspace disbursement drafts to the Accounting tenant';

    public function handle(WorkspaceAccountingHandoff $handoff, TenantConnectionManager $connections): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $sent = 0;
        $tenants = TenantDatabase::forProduct(TenantDatabase::PRODUCT_WORKSPACE)
            ->where('status', 'active')->whereHas('company')->with('company')->lazyById();

        foreach ($tenants as $tenant) {
            try {
                $connections->connect($tenant);
                foreach ($handoff->pending($limit) as $document) {
                    $sent += (int) $handoff->deliver($tenant->company, $document);
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->warn('Pengiriman tertunda untuk perusahaan '.$tenant->company_id.'.');
            } finally {
                $connections->disconnect('workspace');
            }
        }

        $this->info($sent.' draf pencairan berhasil dikirim.');

        return self::SUCCESS;
    }
}
