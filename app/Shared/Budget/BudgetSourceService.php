<?php

namespace App\Shared\Budget;

use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class BudgetSourceService
{
    public function __construct(private readonly BudgetPeriodOverlapReader $accounting) {}

    public function periods(int $companyId): Builder
    {
        return DB::table('workspace_budget_periods')->where('company_id', $companyId);
    }

    public function exclusive(int $companyId, Closure $work): mixed
    {
        return DB::transaction(function () use ($companyId, $work) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::table('companies')->where('id', $companyId)->update(['id' => DB::raw('id')]);
            }
            $company = Company::query()->lockForUpdate()->findOrFail($companyId);

            return $work($company);
        }, 3);
    }

    public function createWorkspacePeriod(int $companyId, string $name, string $from, int $actor): object
    {
        $to = CarbonImmutable::createFromFormat('Y-m-d', $from)->addYear()->subDay()->toDateString();

        return $this->exclusive($companyId, function (Company $company) use ($name, $from, $to, $actor) {
            if ($this->overlapsWorkspace($company->id, $from, $to)) {
                throw ApiException::make('WORKSPACE_PERIOD_OVERLAP', 'Tanggal beririsan dengan periode Workspace yang sudah ada.', 422);
            }

            if ($this->accounting->overlaps($company, $from, $to)) {
                throw ApiException::make('ACCOUNTING_BUDGET_PERIOD_EXISTS', 'Budget Accounting sudah memiliki periode yang beririsan. Pilih awal periode Workspace yang berbeda.', 422);
            }

            $id = DB::table('workspace_budget_periods')->insertGetId([
                'company_id' => $company->id,
                'name' => $name,
                'period_from' => $from,
                'period_to' => $to,
                'created_by' => $actor,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->periods($company->id)->find($id);
        });
    }

    public function assertAccountingPeriod(int $companyId, string $from, string $to): void
    {
        if ($this->overlapsWorkspace($companyId, $from, $to)) {
            throw ApiException::make('WORKSPACE_IS_BUDGET_SOURCE', 'Tanggal beririsan dengan periode anggaran yang dikelola di Finlite Workspace.', 422);
        }
    }

    private function overlapsWorkspace(int $companyId, string $from, string $to): bool
    {
        return $this->periods($companyId)
            ->whereDate('period_from', '<=', CarbonImmutable::parse($to)->toDateString())
            ->whereDate('period_to', '>=', CarbonImmutable::parse($from)->toDateString())
            ->exists();
    }
}
