<?php

namespace App\Http\Controllers\Api\Journal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Journal\ApproveJournalEntryRequest;
use App\Http\Requests\Journal\PostJournalEntryRequest;
use App\Http\Requests\Journal\StoreJournalEntryRequest;
use App\Http\Requests\Journal\UpdateJournalEntryRequest;
use App\Http\Requests\Journal\VoidJournalEntryRequest;
use App\Models\Tenant\JournalEntry;
use App\Services\Journal\JournalEntryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly JournalEntryService $service)
    {
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
        return $this->successResponse($journal, 'Journal posted successfully');
    }

    public function void(VoidJournalEntryRequest $request, int $id): JsonResponse
    {
        $journal = JournalEntry::query()->findOrFail($id);
        $journal = $this->service->void($journal, (string) $request->validated('reason'), auth()->id());
        return $this->successResponse($journal, 'Journal voided successfully');
    }
}

