<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Models\ProductCategory;
use App\Shared\Api\AppliesListQuery;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ProductCategoryService
{
    use AppliesListQuery;

    protected array $listSearchable = ['name'];

    protected array $listSearchableRelations = [];

    protected string $listDateColumn = '';

    protected string $listStatusColumn = 'is_active';

    protected array $listDefaultSort = ['name' => 'asc'];

    protected array $listSortable = ['name', 'is_active'];

    /**
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator|Collection<int,ProductCategory>
     */
    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $query = ProductCategory::query();

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $this->applyListQuery($query, $filters);
    }

    public function create(array $data): ProductCategory
    {
        if (! empty($data['parent_category_id']) && ! ProductCategory::query()->whereKey((int) $data['parent_category_id'])->exists()) {
            throw ApiException::make('PARENT_CATEGORY_NOT_FOUND', 'Parent category not found.', 422);
        }

        return ProductCategory::query()->create($data);
    }

    public function update(ProductCategory $category, array $data): ProductCategory
    {
        if (array_key_exists('parent_category_id', $data)) {
            $parentId = $data['parent_category_id'];
            if ($parentId !== null && (int) $parentId === (int) $category->id) {
                throw ApiException::make('INVALID_PARENT_CATEGORY', 'parent_category_id cannot be self.', 422);
            }
            if ($parentId !== null && ! ProductCategory::query()->whereKey((int) $parentId)->exists()) {
                throw ApiException::make('PARENT_CATEGORY_NOT_FOUND', 'Parent category not found.', 422);
            }
        }

        $category->fill($data);
        $category->save();

        return $category->refresh();
    }

    public function deactivate(ProductCategory $category): ProductCategory
    {
        $category->is_active = false;
        $category->save();

        return $category->refresh();
    }

    public function activate(ProductCategory $category): ProductCategory
    {
        $category->is_active = true;
        $category->save();

        return $category->refresh();
    }
}
