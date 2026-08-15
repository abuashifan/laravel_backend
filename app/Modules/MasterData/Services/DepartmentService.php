<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Services\Concerns\ParsesBooleanFilters;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Api\AppliesListQuery;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class DepartmentService
{
    use AppliesListQuery;
    use ParsesBooleanFilters;

    /**
     * Cost center berjenjang dibatasi 5 tingkat: cukup untuk
     * Perusahaan → Direktorat → Divisi → Departemen → Unit, dan menjaga
     * drill-down laporan tetap terbaca.
     */
    public const MAX_HIERARCHY_DEPTH = 5;

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
            $query->where('is_active', $this->toBool($filters['is_active']));
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

        if (! empty($data['parent_id'])) {
            $this->assertDepthWithinLimit((int) $data['parent_id']);
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

        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
            $this->assertNoCycle($department, (int) $data['parent_id']);
            $this->assertDepthWithinLimit((int) $data['parent_id'], $department);
        }

        $department->fill($data);
        $department->save();

        return $department->refresh();
    }

    /**
     * Induk tidak boleh dirinya sendiri maupun salah satu keturunannya — dua-duanya
     * membuat rantai `parent_id` melingkar, dan setiap penelusuran hierarki
     * (drill-down cost center) akan berputar tanpa henti.
     */
    private function assertNoCycle(Department $department, int $parentId): void
    {
        if ($parentId === (int) $department->id) {
            throw ApiException::make(
                ApiErrorCode::DEPARTMENT_HIERARCHY_CYCLE,
                'Departemen tidak boleh menjadi induk dari dirinya sendiri.',
                422,
                ['parent_id' => ['Departemen tidak boleh menjadi induk dari dirinya sendiri.']],
            );
        }

        foreach ($this->ancestorIds($parentId) as $ancestorId) {
            if ($ancestorId === (int) $department->id) {
                throw ApiException::make(
                    ApiErrorCode::DEPARTMENT_HIERARCHY_CYCLE,
                    'Departemen tidak boleh dipindahkan ke bawah keturunannya sendiri.',
                    422,
                    ['parent_id' => ['Departemen tidak boleh dipindahkan ke bawah keturunannya sendiri.']],
                );
            }
        }
    }

    /**
     * Saat memindahkan cabang, yang dibatasi bukan cuma posisi node-nya tapi juga
     * keturunan terdalamnya — memindahkan subtree 3 level ke bawah induk level 4
     * menghasilkan level 6.
     */
    private function assertDepthWithinLimit(int $parentId, ?Department $moving = null): void
    {
        $newDepth = $this->depthOf($parentId) + 1;
        $deepest = $newDepth + ($moving ? $this->subtreeHeight($moving) - 1 : 0);

        if ($deepest > self::MAX_HIERARCHY_DEPTH) {
            throw ApiException::make(
                ApiErrorCode::DEPARTMENT_HIERARCHY_TOO_DEEP,
                'Hierarki departemen maksimal '.self::MAX_HIERARCHY_DEPTH.' tingkat.',
                422,
                ['parent_id' => ['Hierarki departemen maksimal '.self::MAX_HIERARCHY_DEPTH.' tingkat.']],
            );
        }
    }

    /** Kedalaman 1 = akar. */
    private function depthOf(int $departmentId): int
    {
        return count($this->ancestorIds($departmentId)) + 1;
    }

    /**
     * Id leluhur dari yang terdekat ke akar, tidak termasuk dirinya sendiri.
     *
     * @return array<int,int>
     */
    private function ancestorIds(int $departmentId): array
    {
        $ancestors = [];
        $currentId = $departmentId;

        // Batas iterasi menjaga data yang sudah terlanjur melingkar (mis. hasil
        // impor langsung ke DB) tidak mengunci proses di loop tanpa akhir.
        for ($i = 0; $i < self::MAX_HIERARCHY_DEPTH + 1; $i++) {
            $parentId = Department::query()->whereKey($currentId)->value('parent_id');
            if ($parentId === null) {
                break;
            }

            $ancestors[] = (int) $parentId;
            $currentId = (int) $parentId;
        }

        return $ancestors;
    }

    /** Tinggi subtree; daun = 1. */
    private function subtreeHeight(Department $department, int $guard = 0): int
    {
        if ($guard >= self::MAX_HIERARCHY_DEPTH) {
            return 1;
        }

        $children = Department::query()->where('parent_id', $department->id)->get();

        if ($children->isEmpty()) {
            return 1;
        }

        return 1 + $children->max(fn (Department $child) => $this->subtreeHeight($child, $guard + 1));
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
