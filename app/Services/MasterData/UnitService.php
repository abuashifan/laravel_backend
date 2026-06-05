<?php

namespace App\Services\MasterData;

use App\Exceptions\ApiException;
use App\Models\Tenant\Unit;

class UnitService
{
    public function list(array $filters = [])
    {
        $query = Unit::query();

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query->orderBy('code')->get();
    }

    public function create(array $data): Unit
    {
        if (Unit::query()->where('code', (string) $data['code'])->exists()) {
            throw ApiException::make('DUPLICATE_UNIT_CODE', 'Unit code is already in use.', 422, [
                'code' => ['Code is already in use.'],
            ]);
        }

        return Unit::query()->create($data);
    }

    public function update(Unit $unit, array $data): Unit
    {
        if (! empty($data['code']) && $data['code'] !== $unit->code) {
            if (Unit::query()->where('code', (string) $data['code'])->exists()) {
                throw ApiException::make('DUPLICATE_UNIT_CODE', 'Unit code is already in use.', 422, [
                    'code' => ['Code is already in use.'],
                ]);
            }
        }

        $unit->fill($data);
        $unit->save();

        return $unit->refresh();
    }

    public function deactivate(Unit $unit): Unit
    {
        $unit->is_active = false;
        $unit->save();

        return $unit->refresh();
    }

    public function activate(Unit $unit): Unit
    {
        $unit->is_active = true;
        $unit->save();

        return $unit->refresh();
    }
}
