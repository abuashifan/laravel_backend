<?php

namespace App\Http\Controllers\Api\Journal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Journal\ApproveJournalEntryRequest;
use App\Http\Requests\Journal\PostJournalEntryRequest;
use App\Http\Requests\Journal\StoreJournalEntryRequest;
use App\Http\Requests\Journal\UpdateJournalEntryRequest;
use App\Http\Requests\Journal\VoidJournalEntryRequest;
use App\Models\Tenant\JournalEntry;
use App\Services\Budget\BudgetWarningService;
use App\Services\Journal\JournalEntryService;
use App\Services\Tenant\TenantContext;
use App\Support\Api\ApiResponseBuilder;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly JournalEntryService $service,
        private readonly BudgetWarningService $budgetWarning,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->service->list($request->query());

        return $this->listResponse($items, $request, 'Journals retrieved successfully');
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $journal = $this->service->createManual($request->validated());

        return $this->successResponse($journal, 'Journal created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $journal = $this->service->find($id);

        return $this->successResponse($journal, 'Journal retrieved successfully');
    }

    public function update(UpdateJournalEntryRequest $request, int $id): JsonResponse
    {
        $journal = JournalEntry::query()->findOrFail($id);
        $journal = $this->service->updateManual($journal, $request->validated());

        return $this->successResponse($journal, 'Journal updated successfully');
    }

    public function approve(ApproveJournalEntryRequest $request, int $id): JsonResponse
    {
        $journal = JournalEntry::query()->findOrFail($id);
        $journal = $this->service->approve($journal, auth()->id());

        return $this->successResponse($journal, 'Journal approved successfully');
    }

    public function post(PostJournalEntryRequest $request, int $id): JsonResponse
    {
        $journal = JournalEntry::query()->findOrFail($id);
        $journal = $this->service->post($journal, auth()->id());

        $warnings = $this->collectBudgetWarnings($journal);

        return ApiResponseBuilder::success($journal, 'Journal posted successfully', 200, ['warnings' => $warnings]);
    }

    /** @return list<array<string,mixed>> */
    private function collectBudgetWarnings(JournalEntry $journal): array
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return [];
        }

        $journal->loadMissing('lines');

        $warnings = [];
        $journalDate = $journal->journal_date?->format('Y-m');

        foreach ($journal->lines as $line) {
            $amount = (float) $line->debit - (float) $line->credit;
            if ($amount <= 0 || ! $line->account_id || ! $journalDate) {
                continue;
            }

            $warning = $this->budgetWarning->check(
                companyId: $company->id,
                accountId: $line->account_id,
                departmentId: $line->department_id,
                projectId: $line->project_id,
                period: $journalDate,
                amountToPost: $amount,
            );

            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }

    public function void(VoidJournalEntryRequest $request, int $id): JsonResponse
    {
        $journal = JournalEntry::query()->findOrFail($id);
        $journal = $this->service->void($journal, (string) $request->validated('reason'), auth()->id());

        return $this->successResponse($journal, 'Journal voided successfully');
    }
}
