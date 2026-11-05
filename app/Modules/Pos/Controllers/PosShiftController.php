<?php

namespace App\Modules\Pos\Controllers;

use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Services\PosShiftService;
use App\Shared\Permission\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosShiftController extends PosController
{
    public function __construct(
        private readonly PosShiftService $shifts,
        private readonly PermissionService $permissions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PosShift::query()->latest('opened_at');

        // Tanpa izin khusus, kasir hanya melihat shift miliknya sendiri.
        if (! $this->permissions->can('pos.shifts.view_all')) {
            $query->where('user_id', $this->user($request)->id);
        }
        if ($status = $request->query('status')) {
            $query->where('status', (string) $status);
        }

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function current(Request $request): JsonResponse
    {
        $shift = $this->shifts->current($this->user($request));

        return $this->successResponse($shift ? ['shift' => $shift, 'summary' => $this->shifts->summary($shift)] : null);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $shift = $this->findVisible($request, $id);

        return $this->successResponse(['shift' => $shift, 'summary' => $this->shifts->summary($shift)]);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $shift = $this->shifts->open($this->user($request), (float) $data['opening_cash'], $data['notes'] ?? null);

        return $this->successResponse(['shift' => $shift, 'summary' => $this->shifts->summary($shift)], 'Shift dibuka.', 201);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $shift = $this->findVisible($request, $id);
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $shift = $this->shifts->close($shift, (float) $data['counted_cash'], $data['notes'] ?? null);

        return $this->successResponse(['shift' => $shift, 'summary' => $this->shifts->summary($shift)], 'Shift ditutup.');
    }

    private function findVisible(Request $request, int $id): PosShift
    {
        $shift = PosShift::query()->findOrFail($id);
        abort_if(
            $shift->user_id !== $this->user($request)->id && ! $this->permissions->can('pos.shifts.view_all'),
            403,
            'Shift ini milik kasir lain.',
        );

        return $shift;
    }
}
