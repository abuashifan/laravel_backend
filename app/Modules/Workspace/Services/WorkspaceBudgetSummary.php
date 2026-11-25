<?php

namespace App\Modules\Workspace\Services;

use App\Shared\Budget\WorkspaceBudgetSummaryReader;
use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\TenantConnectionManager;
use App\Shared\Tenant\TenantProductService;
use Illuminate\Support\Facades\DB;

class WorkspaceBudgetSummary implements WorkspaceBudgetSummaryReader
{
    public function __construct(
        private readonly TenantProductService $products,
        private readonly TenantConnectionManager $connections,
    ) {}

    public function totalsByPeriod(Company $company): ?array
    {
        $workspace = $this->products->find($company, TenantDatabase::PRODUCT_WORKSPACE);
        if ($workspace?->status !== 'active') {
            return null;
        }

        $this->connections->connect($workspace);
        try {
            return DB::connection('workspace')->table('workspace_budget_items as i')
                ->join('workspace_budget_plans as p', 'p.id', '=', 'i.plan_id')
                ->where('i.is_active', true)->groupBy('p.period_id')
                ->selectRaw('p.period_id, SUM(i.limit_minor) AS limit_minor, SUM(i.committed_minor) AS committed_minor')
                ->get()->mapWithKeys(fn ($row) => [$row->period_id => [
                    'limit_minor' => (string) $row->limit_minor,
                    'committed_minor' => (string) $row->committed_minor,
                ]])->all();
        } finally {
            $this->connections->disconnect('workspace');
        }
    }
}
