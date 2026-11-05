<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Api\ApiResponse;
use App\Shared\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Dasar controller modul POS: bentuk response daftar sama dengan modul lain. */
abstract class PosController extends Controller
{
    use ApiResponse;

    protected function paginated(LengthAwarePaginator $page, string $message = 'Success'): JsonResponse
    {
        return $this->successResponse([
            'data' => $page->items(),
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
        ], $message);
    }

    protected function perPage(Request $request, int $default = 25): int
    {
        return max(1, min(100, (int) $request->query('per_page', (string) $default)));
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** Pencarian teks yang tidak peka huruf besar/kecil di SQLite maupun Postgres. */
    protected function containsInsensitive(string $column, string $term): array
    {
        return ['LOWER('.$column.') LIKE ?', ['%'.mb_strtolower($term).'%']];
    }
}
