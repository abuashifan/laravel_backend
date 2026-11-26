<?php

namespace App\Modules\Workspace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workspace\Models\WorkspaceUnit;
use App\Modules\Workspace\Requests\SaveWorkspaceUnitRequest;
use App\Modules\Workspace\Services\WorkspaceUnitService;
use App\Shared\Api\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkspaceUnitController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WorkspaceUnitService $units) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'in:25,50,100']]);
        $search = trim($data['search'] ?? '');
        $rows = $this->units->visible()
            ->when($search !== '', fn ($query) => $query->where(fn ($matches) => $matches->whereLike('code', '%'.$search.'%')->orWhereLike('name', '%'.$search.'%')))
            ->orderBy('code')->paginate((int) ($data['per_page'] ?? 25), ['*'], 'page', (int) ($data['page'] ?? 1));

        return $this->successResponse(['data' => $rows->items(), 'total' => $rows->total()]);
    }

    public function store(SaveWorkspaceUnitRequest $request): JsonResponse
    {
        try {
            $unit = WorkspaceUnit::query()->create($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Kode unit sudah digunakan.']);
        }

        return $this->successResponse($unit, 'Unit dibuat.', 201);
    }

    public function update(SaveWorkspaceUnitRequest $request, int $unit): JsonResponse
    {
        $record = WorkspaceUnit::query()->findOrFail($unit);
        try {
            $record->update($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Kode unit sudah digunakan.']);
        }

        return $this->successResponse($record, 'Unit diperbarui.');
    }

    public function members(int $unit): JsonResponse
    {
        return $this->successResponse($this->units->members($unit));
    }

    public function assign(int $unit, int $member): JsonResponse
    {
        $this->units->assign($unit, $member);

        return $this->successResponse(null, 'Anggota ditambahkan ke unit.');
    }

    public function remove(int $unit, int $member): JsonResponse
    {
        $this->units->remove($unit, $member);

        return $this->successResponse(null, 'Penempatan anggota dihapus.');
    }
}
