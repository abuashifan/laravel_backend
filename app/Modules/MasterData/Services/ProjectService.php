<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Models\Project;
use App\Modules\MasterData\Services\Concerns\ParsesBooleanFilters;
use App\Shared\Api\AppliesListQuery;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ProjectService
{
    use AppliesListQuery;
    use ParsesBooleanFilters;

    protected array $listSearchable = ['code', 'name'];

    protected array $listSearchableRelations = [];

    protected string $listDateColumn = '';

    /**
     * SATU-SATUNYA master data yang punya kolom `status` string sendiri di
     * samping `is_active` (diverifikasi lewat PRAGMA table_info). Jadi
     * `?status=` di sini menyaring kolom status proyek apa adanya, BUKAN
     * dipetakan ke boolean seperti delapan service master data lainnya --
     * persis seperti perilaku lama: `applyListStatus` in-memory memeriksa
     * kunci `status` lebih dulu dan berhenti di situ. Filter `is_active`
     * tetap dikirim terpisah.
     */
    protected string $listStatusColumn = 'status';

    protected array $listDefaultSort = ['code' => 'asc'];

    protected array $listSortable = ['code', 'name', 'status', 'is_active'];

    /**
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator|Collection<int,Project>
     */
    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $query = Project::query();

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $this->toBool($filters['is_active']));
        }

        return $this->applyListQuery($query, $filters);
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
