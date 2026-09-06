<?php

namespace App\Modules\OpeningBalance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\OpeningBalance\Requests\CloseClearingRequest;
use App\Modules\OpeningBalance\Requests\SetOpeningDateRequest;
use App\Modules\OpeningBalance\Requests\VoidOpeningJournalRequest;
use App\Modules\OpeningBalance\Services\OpeningBalanceService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class OpeningBalanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OpeningBalanceService $service) {}

    /**
     * Seluruh isi papan pemantau dalam satu panggilan: tanggal, saldo
     * perantara, daftar jurnal pembuka, dan rekonsiliasi register aset.
     */
    public function status(): JsonResponse
    {
        return $this->successResponse($this->service->status(), 'Opening balance status retrieved successfully');
    }

    public function setOpeningDate(SetOpeningDateRequest $request): JsonResponse
    {
        $date = $this->service->setOpeningDate((string) $request->validated('opening_date'));

        return $this->successResponse(['opening_date' => $date], 'Opening balance date updated successfully');
    }

    public function closeClearing(CloseClearingRequest $request): JsonResponse
    {
        $journal = $this->service->closeClearing(
            $request->validated('targets'),
            $request->validated('description'),
        );

        return $this->successResponse($journal->load('lines.account'), 'Opening balance clearing closed successfully');
    }

    public function voidJournal(VoidOpeningJournalRequest $request, int $id): JsonResponse
    {
        $this->service->voidOpeningJournal($id, (string) $request->validated('reason'));

        return $this->successResponse($this->service->status(), 'Opening balance journal voided successfully');
    }
}
