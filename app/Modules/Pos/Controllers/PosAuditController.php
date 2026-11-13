<?php

namespace App\Modules\Pos\Controllers;

use App\Modules\Pos\Models\PosAuditLog;
use App\Modules\Pos\Services\PosAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Log aktivitas toko (Fase 4b): daftar aksi sensitif, terbaru dulu. */
class PosAuditController extends PosController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['nullable', Rule::in(array_keys(PosAuditService::ACTIONS))],
            'user_id' => ['nullable', 'integer'],
        ]);

        $query = $this->dated(PosAuditLog::query()->latest('created_at')->latest('id'), $request, 'created_at');
        if ($request->filled('action')) {
            $query->where('action', (string) $request->query('action'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->whereRaw(...$this->containsInsensitive('summary', $search))
                    ->orWhereRaw(...$this->containsInsensitive('subject_label', $search))
                    ->orWhereRaw(...$this->containsInsensitive('user_name', $search));
            });
        }

        $page = $query->paginate($this->perPage($request, 30));
        $page->through(fn (PosAuditLog $log) => $log->toArray() + ['action_label' => PosAuditService::ACTIONS[$log->action] ?? $log->action]);

        return $this->paginated($page);
    }

    /** Daftar aksi untuk filter di layar. */
    public function actions(): JsonResponse
    {
        return $this->successResponse(collect(PosAuditService::ACTIONS)
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
            ->values());
    }
}
