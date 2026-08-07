<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Models\Department;
use App\Shared\Api\AppliesListQuery;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class DepartmentService
{
    use AppliesListQuery;

    /** Blok pencarian manual dihapus -- trait mencari kolom yang sama persis. */
    protected array $listSearchable = ['code', 'name'];

    protected array $listSearchableRelations = [];

    protected string $listDateColumn = '';

    protected string $listStatusColumn = 'is_active';

    protected array $listDefaultSort = ['code' => 'asc'];

    protected array $listSortable = ['code', 'name', 'is_active'];

    /**
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator|Collection<int,Department>
     */
    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $query = Department::query();

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $this->applyListQuery($query, $filters);
    }

    public function find(int|string $id): Department
    {
        return Department::query()->findOrFail($id);
    }

    public function create(array $data): Department
    {
        if (Department::query()->where('code', (string) $data['code'])->exists()) {
            throw ApiException::make('DUPLICATE_DEPARTMENT_CODE', 'Department code is already in use.', 422, [
                'code' => ['Code is already in use.'],
            ]);
        }

        return Department::query()->create($data);
    }

    public function update(Department $department, array $data): Department
    {
        if (! empty($data['code']) && $data['code'] !== $department->code) {
            if (Department::query()->where('code', (string) $data['code'])->exists()) {
                throw ApiException::make('DUPLICATE_DEPARTMENT_CODE', 'Department code is already in use.', 422, [
                    'code' => ['Code is already in use.'],
                ]);
            }
        }

        $department->fill($data);
        $department->save();

        return $department->refresh();
    }

    public function deactivate(Department $department): Department
    {
        $department->is_active = false;
        $department->save();

        return $department->refresh();
    }

    public function activate(Department $department): Department
    {
        $department->is_active = true;
        $department->save();

        return $department->refresh();
    }
}
