<?php

namespace App\Services\MasterData;

use App\Exceptions\ApiException;
use App\Models\Tenant\ProductCategory;

class ProductCategoryService
{
    public function list(array $filters = [])
    {
        $query = ProductCategory::query();

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.str_replace('%', '', (string) $filters['search']).'%';
            $query->where('name', 'like', $term);
        }

        return $query->orderBy('name')->get();
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
