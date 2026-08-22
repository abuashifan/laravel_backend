<?php

namespace App\Modules\Journal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Services\BudgetWarningService;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\Journal\Requests\ApproveJournalEntryRequest;
use App\Modules\Journal\Requests\PostJournalEntryRequest;
use App\Modules\Journal\Requests\StoreJournalEntryRequest;
use App\Modules\Journal\Requests\UpdateJournalEntryRequest;
use App\Modules\Journal\Requests\VoidJournalEntryRequest;
use App\Modules\Journal\Services\JournalEntryService;
use App\Shared\Api\ApiResponse;
use App\Shared\Api\ApiResponseBuilder;
use App\Shared\Api\ResolvesAdjacentRecords;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
    use ApiResponse;
    use ResolvesAdjacentRecords;

    public function __construct(
        private readonly JournalEntryService $service,
        private readonly BudgetWarningService $budgetWarning,
        private readonly TenantContext $tenantContext,
    ) {}

    public function adjacent(Request $request): JsonResponse
    {
        return $this->adjacentResponse(JournalEntry::query(), $request, 'journal_number');
    }

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
