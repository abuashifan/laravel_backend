<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Services\PosClock;
use App\Shared\Api\ApiResponse;
use App\Shared\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Filter `date_from` / `date_to` (tanggal toko, inklusif) pada kolom waktu.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function dated(Builder $query, Request $request, string $column): Builder
    {
        $clock = app(PosClock::class);
        if ($request->filled('date_from')) {
            $query->where($column, '>=', $clock->startOfDay((string) $request->query('date_from')));
        }
        if ($request->filled('date_to')) {
            $query->where($column, '<=', $clock->endOfDay((string) $request->query('date_to')));
        }

        return $query;
    }

    /** Pencarian teks yang tidak peka huruf besar/kecil di SQLite maupun Postgres. */
    protected function containsInsensitive(string $column, string $term): array
    {
        return ['LOWER('.$column.') LIKE ?', ['%'.mb_strtolower($term).'%']];
    }
}
