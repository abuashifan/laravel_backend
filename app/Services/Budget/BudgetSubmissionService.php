<?php

namespace App\Services\Budget;

use App\Exceptions\ApiException;
use App\Models\Tenant\BudgetLine;
use App\Models\Tenant\BudgetPeriod;
use App\Models\Tenant\BudgetSubmission;
use App\Services\Settings\CompanySettingService;
use App\Services\Tenant\TenantContext;
use App\Support\Api\ApiErrorCode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BudgetSubmissionService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CompanySettingService $companySettingService,
    ) {}

    /** @return Collection<int,BudgetSubmission> */
    public function list(BudgetPeriod $period, array $filters = []): Collection
    {
        $companyId = $this->tenantContext->companyId();
        $query = BudgetSubmission::query()
            ->forCompany($companyId)
            ->where('budget_period_id', $period->id)
            ->with('department');

        if (! empty($filters['department_id'])) {
            $query->where('department_id', (int) $filters['department_id']);
        }

        return $query->orderBy('department_id')->get();
    }

    public function create(BudgetPeriod $period, array $data): BudgetSubmission
    {
        $companyId = $this->tenantContext->companyId();

        $existing = BudgetSubmission::query()
            ->forCompany($companyId)
            ->where('budget_period_id', $period->id)
            ->where('department_id', $data['department_id'])
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'A submission already exists for this department in this period.', 422);
        }

        return DB::transaction(function () use ($period, $data, $companyId) {
            return BudgetSubmission::query()->create([
                'company_id' => $companyId,
                'budget_period_id' => $period->id,
                'department_id' => $data['department_id'],
                'status' => 'draft',
                'revision_number' => 1,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
        });
    }

    public function find(int $id): BudgetSubmission
    {
        $companyId = $this->tenantContext->companyId();

        return BudgetSubmission::query()
            ->forCompany($companyId)
            ->with(['period', 'department', 'lines.account', 'lines.project'])
            ->findOrFail($id);
    }

    public function update(BudgetSubmission $submission, array $data): BudgetSubmission
    {
        $this->assertEditable($submission);

        $submission->update([
            'notes' => $data['notes'] ?? $submission->notes,
        ]);

        return $submission->refresh();
    }

    public function updateLines(BudgetSubmission $submission, array $lines): BudgetSubmission
    {
        $this->assertEditable($submission);
        $this->validateLinesUnique($lines);

        return DB::transaction(function () use ($submission, $lines) {
            $submission->lines()->delete();

            $toInsert = array_map(fn ($line) => [
                'budget_submission_id' => $submission->id,
                'account_id' => $line['account_id'],
                'project_id' => $line['project_id'] ?? null,
                'period' => $line['period'] ?? null,
                'amount' => $line['amount'],
                'notes' => $line['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ], $lines);

            BudgetLine::query()->insert($toInsert);

            return $submission->load('lines.account', 'lines.project');
        });
    }

    public function submit(BudgetSubmission $submission): BudgetSubmission
    {
        $this->assertEditable($submission);

        $company = $this->tenantContext->company();
        $workflow = $this->companySettingService->getOrCreateAccountingSetting($company);
        $autoPost = $workflow->transaction_workflow_mode === 'simple_auto_post' && (bool) $workflow->auto_post_transactions;

        return DB::transaction(function () use ($submission, $autoPost) {
            $userId = auth()->id();
            $now = now();

            if ($autoPost) {
                $submission->update([
                    'status' => 'approved',
                    'submitted_by_id' => $userId,
                    'submitted_at' => $now,
                    'approved_by_finance_id' => $userId,
                    'approved_by_finance_at' => $now,
                ]);
            } else {
                $submission->update([
                    'status' => 'submitted',
                    'submitted_by_id' => $userId,
                    'submitted_at' => $now,
                ]);
            }

            return $submission->refresh();
        });
    }

    public function approveHead(BudgetSubmission $submission): BudgetSubmission
    {
        if ($submission->status !== 'submitted') {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Submission must be in submitted status to approve as head.', 422);
        }

        $submission->update([
            'status' => 'approved_by_head',
            'approved_by_head_id' => auth()->id(),
            'approved_by_head_at' => now(),
        ]);

        return $submission->refresh();
    }

    public function approveFinance(BudgetSubmission $submission): BudgetSubmission
    {
        if ($submission->status !== 'approved_by_head') {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Submission must be approved by head first.', 422);
        }

        $submission->update([
            'status' => 'approved',
            'approved_by_finance_id' => auth()->id(),
            'approved_by_finance_at' => now(),
        ]);

        return $submission->refresh();
    }

    public function reject(BudgetSubmission $submission, string $rejectionNote): BudgetSubmission
    {
        $allowedStatuses = ['submitted', 'approved_by_head'];
        if (! in_array($submission->status, $allowedStatuses, true)) {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Submission cannot be rejected from its current status.', 422);
        }

        $submission->update([
            'status' => 'draft',
            'revision_number' => $submission->revision_number + 1,
            'rejected_by_id' => auth()->id(),
            'rejected_at' => now(),
            'rejection_note' => $rejectionNote,
            // Reset approval fields
            'approved_by_head_id' => null,
            'approved_by_head_at' => null,
            'approved_by_finance_id' => null,
            'approved_by_finance_at' => null,
        ]);

        return $submission->refresh();
    }

    private function assertEditable(BudgetSubmission $submission): void
    {
        if (! in_array($submission->status, ['draft', 'rejected'], true)) {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Submission can only be edited in draft or rejected status.', 422);
        }
    }

    private function validateLinesUnique(array $lines): void
    {
        $seen = [];
        foreach ($lines as $line) {
            $key = ($line['account_id'] ?? '').'|'.($line['project_id'] ?? 'null').'|'.($line['period'] ?? 'null');
            if (isset($seen[$key])) {
                throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Duplicate budget line: same account, project, and period.', 422);
            }
            $seen[$key] = true;
        }
    }
}
