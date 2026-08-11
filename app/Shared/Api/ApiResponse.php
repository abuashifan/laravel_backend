<?php

namespace App\Shared\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use LogicException;

trait ApiResponse
{
    protected function successResponse(
        mixed $data = null,
        string $message = 'Success',
        int $status = 200,
        array $meta = []
    ) {
        return ApiResponseBuilder::success($data, $message, $status, $meta);
    }

    /**
     * Membentuk response daftar. **Tidak lagi menyaring apa pun** — service
     * yang memakai `AppliesListQuery` sudah mengerjakan search/status/tanggal/
     * sort/paginate di SQL (Fase 1-6 list-query-pushdown).
     *
     * Dua cabangnya harus simetris dengan `AppliesListQuery::applyListQuery()`:
     * keduanya memutuskan "paginasi atau tidak" dari ada-tidaknya `page`/
     * `per_page`. Kalau salah satu sisi diubah tanpa yang lain, cabang di
     * bawah akan menerima bentuk yang tidak diharapkan.
     */
    protected function listResponse(
        mixed $items,
        Request $request,
        string $message = 'Success',
        int $status = 200
    ) {
        // Kontrak lama yang tetap dipakai beberapa pemanggil internal:
        // tanpa page/per_page berarti "kirim semua, jangan dipaginasi".
        if (! $request->hasAny(['page', 'per_page'])) {
            return $this->successResponse($items, $message, $status);
        }

        if (! $items instanceof LengthAwarePaginator) {
            // Sampai Fase 6 ada jalur cadangan yang menyaring & memotong
            // Collection di memori PHP. Jalur itu dihapus di Fase 7 setelah
            // ke-32 endpoint daftar pindah ke SQL. Sengaja gagal keras: dulu
            // service yang lupa dipindah tetap "jalan" diam-diam lewat jalur
            // lambat itu, dan justru itu yang menyembunyikan masalahnya
            // berbulan-bulan.
            throw new LogicException(sprintf(
                'listResponse() menerima %s, bukan LengthAwarePaginator. Service '.
                'yang dipanggil harus memakai trait AppliesListQuery dan meneruskan '.
                '$request->query() ke applyListQuery().',
                get_debug_type($items),
            ));
        }

        return $this->successResponse([
            'data' => $items->items(),
            'current_page' => $items->currentPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
            'last_page' => $items->lastPage(),
            'from' => $items->firstItem(),
            'to' => $items->lastItem(),
        ], $message, $status);
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
