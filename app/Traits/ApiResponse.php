<?php

namespace App\Traits;

use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponseBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

trait ApiResponse
{
    protected function successResponse(
        mixed $data = null,
        string $message = 'Success',
        int $status = 200
    ) {
        return ApiResponseBuilder::success($data, $message, $status);
    }

    protected function listResponse(
        mixed $items,
        Request $request,
        string $message = 'Success',
        int $status = 200
    ) {
        if (! $request->hasAny(['page', 'per_page'])) {
            return $this->successResponse($items, $message, $status);
        }

        $collection = $items instanceof Collection ? $items->values() : collect($items)->values();
        $collection = $this->applyListSearch($collection, (string) $request->query('search', ''));
        $collection = $this->applyListStatus($collection, $request->query('status'));
        $collection = $this->applyListDateRange(
            $collection,
            $request->query('start_date', $request->query('date_from')),
            $request->query('end_date', $request->query('date_to')),
        );
        $collection = $this->applyListSort(
            $collection,
            (string) $request->query('sort_by', $request->query('sort', '')),
            (string) $request->query('sort_direction', $request->query('direction', 'asc')),
        );

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 10)));
        $total = $collection->count();
        $pageItems = $collection->slice(($page - 1) * $perPage, $perPage)->values();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $from = $total === 0 ? null : (($page - 1) * $perPage) + 1;
        $to = $total === 0 ? null : min($page * $perPage, $total);

        return $this->successResponse([
            'data' => $pageItems,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'from' => $from,
            'to' => $to,
        ], $message, $status);
    }

    private function applyListSearch(Collection $items, string $search): Collection
    {
        $term = trim(mb_strtolower($search));
        if ($term === '') {
            return $items;
        }

        return $items->filter(function (mixed $item) use ($term): bool {
            foreach ($this->listItemValues($item) as $value) {
                if (is_scalar($value) && str_contains(mb_strtolower((string) $value), $term)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    private function applyListStatus(Collection $items, mixed $status): Collection
    {
        if ($status === null || $status === '') {
            return $items;
        }

        // Mendukung satu status maupun beberapa status comma-separated (mis. "draft,posted").
        $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $status)), fn (string $s): bool => $s !== ''));
        if ($statuses === []) {
            return $items;
        }

        return $items->filter(function (mixed $item) use ($statuses): bool {
            foreach (['status', 'state', 'is_active'] as $key) {
                $value = data_get($item, $key);
                if ($value === null) {
                    continue;
                }
                $normalized = is_bool($value) ? ($value ? 'active' : 'inactive') : (string) $value;

                return in_array($normalized, $statuses, true);
            }

            return false;
        })->values();
    }

    private function applyListDateRange(Collection $items, mixed $startDate, mixed $endDate): Collection
    {
        if (($startDate === null || $startDate === '') && ($endDate === null || $endDate === '')) {
            return $items;
        }

        return $items->filter(function (mixed $item) use ($startDate, $endDate): bool {
            $value = $this->listDateValue($item);
            if ($value === '') {
                return true;
            }
            if ($startDate !== null && $startDate !== '' && $value < (string) $startDate) {
                return false;
            }
            if ($endDate !== null && $endDate !== '' && $value > (string) $endDate) {
                return false;
            }

            return true;
        })->values();
    }

    private function applyListSort(Collection $items, string $sortBy, string $direction): Collection
    {
        if ($sortBy === '') {
            return $items;
        }

        $sorted = $items->sortBy(
            fn (mixed $item): mixed => data_get($item, $sortBy),
            SORT_REGULAR,
            mb_strtolower($direction) === 'desc',
        );

        return $sorted->values();
    }

    private function listDateValue(mixed $item): string
    {
        foreach ([
            'document_date',
            'date',
            'transaction_date',
            'quotation_date',
            'order_date',
            'delivery_date',
            'proforma_date',
            'invoice_date',
            'receipt_date',
            'return_date',
            'request_date',
            'po_date',
            'goods_receipt_date',
            'bill_date',
            'deposit_date',
            'payment_date',
            'transfer_date',
            'reconciliation_date',
            'movement_date',
            'adjustment_date',
            'opname_date',
            'created_at',
        ] as $key) {
            $value = data_get($item, $key);
            if ($value !== null && $value !== '') {
                return substr((string) $value, 0, 10);
            }
        }

        return '';
    }

    private function listItemValues(mixed $item): array
    {
        if (is_array($item)) {
            return $item;
        }
        if (is_object($item) && method_exists($item, 'toArray')) {
            return $item->toArray();
        }
        if (is_object($item)) {
            return get_object_vars($item);
        }

        return [$item];
    }

    protected function errorResponse(
        string $message = 'Error',
        int $status = 400,
        mixed $errors = null
    ) {
        return ApiResponseBuilder::error(ApiErrorCode::UNKNOWN_ERROR, $message, (array) ($errors ?? []), $status);
    }

    protected function errorCodeResponse(
        string $code,
        ?string $message = null,
        array $errors = [],
        int $status = 400,
        array $meta = []
    ) {
        return ApiResponseBuilder::error($code, $message, $errors, $status, $meta);
    }

    protected function warningResponse(
        string $code,
        ?string $message = null,
        array $errors = [],
        array $meta = [],
        int $status = 409
    ) {
        return ApiResponseBuilder::warning($code, $message, $errors, $meta, $status);
    }

    protected function validationErrorResponse(array $errors, string $message = 'The given data was invalid.', array $meta = [])
    {
        return ApiResponseBuilder::validation($errors, $message, $meta);
    }

    protected function policyResponse(mixed $policyResult, int $denyStatus = 403)
    {
        return ApiResponseBuilder::fromPolicyResult($policyResult, $denyStatus);
    }
}
