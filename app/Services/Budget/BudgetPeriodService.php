<?php

namespace App\Services\Budget;

use App\Exceptions\ApiException;
use App\Models\Tenant\BudgetPeriod;
use App\Services\Tenant\TenantContext;
use App\Support\Api\ApiErrorCode;
use Illuminate\Database\Eloquent\Collection;

class BudgetPeriodService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /** @return Collection<int,BudgetPeriod> */
    public function list(array $filters = []): Collection
    {
        $companyId = $this->tenantContext->companyId();

        return BudgetPeriod::query()
            ->forCompany($companyId)
            ->withCount('submissions')
            ->orderByDesc('fiscal_year')
            ->get();
    }

    public function create(array $data): BudgetPeriod
    {
        $companyId = $this->tenantContext->companyId();

        return BudgetPeriod::query()->create([
            'company_id' => $companyId,
            'name' => $data['name'],
            'fiscal_year' => $data['fiscal_year'],
            'period_from' => $data['period_from'],
            'period_to' => $data['period_to'],
            'status' => 'open',
            'created_by' => auth()->id(),
        ]);
    }

    public function find(int $id): BudgetPeriod
    {
        $companyId = $this->tenantContext->companyId();

        return BudgetPeriod::query()
            ->forCompany($companyId)
            ->findOrFail($id);
    }

    public function update(BudgetPeriod $period, array $data): BudgetPeriod
    {
        if ($period->status === 'closed') {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Cannot update a closed budget period.', 422);
        }

        $period->update([
            'name' => $data['name'] ?? $period->name,
            'fiscal_year' => $data['fiscal_year'] ?? $period->fiscal_year,
            'period_from' => $data['period_from'] ?? $period->period_from,
            'period_to' => $data['period_to'] ?? $period->period_to,
        ]);

        return $period->refresh();
    }

    public function close(BudgetPeriod $period): BudgetPeriod
    {
        if ($period->status === 'closed') {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Budget period is already closed.', 422);
        }

        $period->update(['status' => 'closed']);

        return $period->refresh();
    }
}
