<?php

namespace App\Services\MasterData;

use App\Exceptions\ApiException;
use App\Models\Tenant\Warehouse;

class WarehouseService
{
    public function list(array $filters = [])
    {
        $query = Warehouse::query();

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.str_replace('%', '', (string) $filters['search']).'%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('code', 'like', $term)->orWhere('name', 'like', $term)->orWhere('address', 'like', $term);
            });
        }

        return $query->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function create(array $data): Warehouse
    {
        if (Warehouse::query()->where('code', (string) $data['code'])->exists()) {
            throw ApiException::make('DUPLICATE_WAREHOUSE_CODE', 'Warehouse code is already in use.', 422, [
                'code' => ['Code is already in use.'],
            ]);
        }

        $warehouse = Warehouse::query()->create($data);

        if ((bool) ($data['is_default'] ?? false)) {
            $this->setDefault($warehouse);
        }

        return $warehouse->refresh();
    }

    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        if (! empty($data['code']) && $data['code'] !== $warehouse->code) {
            if (Warehouse::query()->where('code', (string) $data['code'])->exists()) {
                throw ApiException::make('DUPLICATE_WAREHOUSE_CODE', 'Warehouse code is already in use.', 422, [
                    'code' => ['Code is already in use.'],
                ]);
            }
        }

        $warehouse->fill($data);
        $warehouse->save();

        if (array_key_exists('is_default', $data) && (bool) $data['is_default']) {
            $this->setDefault($warehouse);
        }

        return $warehouse->refresh();
    }

    public function deactivate(Warehouse $warehouse): Warehouse
    {
        if ($warehouse->is_default) {
            throw ApiException::make('CANNOT_DEACTIVATE_DEFAULT_WAREHOUSE', 'Cannot deactivate default warehouse.', 422);
        }

        $warehouse->is_active = false;
        $warehouse->save();

        return $warehouse->refresh();
    }

    public function activate(Warehouse $warehouse): Warehouse
    {
        $warehouse->is_active = true;
        $warehouse->save();

        return $warehouse->refresh();
    }

    public function setDefault(Warehouse $warehouse): Warehouse
    {
        Warehouse::query()->where('id', '!=', $warehouse->id)->update(['is_default' => false]);

        $warehouse->is_default = true;
        $warehouse->save();

        return $warehouse->refresh();
    }
}
