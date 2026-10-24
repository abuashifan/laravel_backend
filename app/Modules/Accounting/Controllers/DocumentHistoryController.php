<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\DocumentHistoryLabelResolver;
use App\Shared\Api\ApiResponse;
use App\Shared\TransactionLifecycle\DocumentHistoryService;
use Illuminate\Http\JsonResponse;

/**
 * Riwayat edit satu dokumen transaksi (tab Riwayat Edit di form). Hanya untuk
 * peran dengan izin `audit.document_history.view`; hanya baca, tanpa restore.
 */
class DocumentHistoryController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DocumentHistoryService $history,
        private readonly DocumentHistoryLabelResolver $labels,
    ) {}

    public function show(string $type, string $id): JsonResponse
    {
        $entries = $this->history->history($type, $id);

        return $this->successResponse([
            'entries' => $entries,
            'labels' => $this->labels->labels($entries),
        ]);
    }
}
