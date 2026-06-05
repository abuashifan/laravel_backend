<?php

namespace App\Services\MasterData;

use App\Exceptions\ApiException;
use App\Models\Tenant\Department;

class DepartmentService
{
    public function list(array $filters = [])
    {
        $query = Department::query();

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace('%', '', (string) $filters['search']).'%';
            $query->where(function ($q) use ($term) {
                $q->where('code', 'like', $term)->orWhere('name', 'like', $term);
            });
        }

        return $query->orderBy('code')->get();
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
