<?php

namespace App\Services\MasterData;

use App\Exceptions\ApiException;
use App\Models\Tenant\Project;

class ProjectService
{
    public function list(array $filters = [])
    {
        $query = Project::query();

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace('%', '', (string) $filters['search']).'%';
            $query->where(function ($q) use ($term) {
                $q->where('code', 'like', $term)->orWhere('name', 'like', $term);
            });
        }

        return $query->orderBy('code')->get();
    }

    public function find(int|string $id): Project
    {
        return Project::query()->findOrFail($id);
    }

    public function create(array $data): Project
    {
        if (Project::query()->where('code', (string) $data['code'])->exists()) {
            throw ApiException::make('DUPLICATE_PROJECT_CODE', 'Project code is already in use.', 422, [
                'code' => ['Code is already in use.'],
            ]);
        }

        return Project::query()->create($data);
    }

    public function update(Project $project, array $data): Project
    {
        if (! empty($data['code']) && $data['code'] !== $project->code) {
            if (Project::query()->where('code', (string) $data['code'])->exists()) {
                throw ApiException::make('DUPLICATE_PROJECT_CODE', 'Project code is already in use.', 422, [
                    'code' => ['Code is already in use.'],
                ]);
            }
        }

        $project->fill($data);
        $project->save();

        return $project->refresh();
    }

    public function deactivate(Project $project): Project
    {
        $project->is_active = false;
        $project->save();

        return $project->refresh();
    }

    public function activate(Project $project): Project
    {
        $project->is_active = true;
        $project->save();

        return $project->refresh();
    }

    public function markCompleted(Project $project): Project
    {
        $project->status = 'completed';
        $project->save();

        return $project->refresh();
    }

    public function markOnHold(Project $project): Project
    {
        $project->status = 'on_hold';
        $project->save();

        return $project->refresh();
    }

    public function cancel(Project $project): Project
    {
        $project->status = 'cancelled';
        $project->save();

        return $project->refresh();
    }
}
