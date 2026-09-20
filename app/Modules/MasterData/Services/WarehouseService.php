<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Models\Warehouse;
use App\Modules\MasterData\Services\Concerns\ParsesBooleanFilters;
use App\Shared\Api\AppliesListQuery;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class WarehouseService
{
    use AppliesListQuery;
    use ParsesBooleanFilters;

    protected array $listSearchable = ['code', 'name'];

    protected array $listSearchableRelations = [];

    protected string $listDateColumn = '';

    protected string $listStatusColumn = 'is_active';

    /** Gudang default selalu di atas -- urutan lama dipertahankan persis. */
    protected array $listDefaultSort = ['is_default' => 'desc', 'name' => 'asc'];

    protected array $listSortable = ['code', 'name', 'is_active'];

    /**
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator|Collection<int,Warehouse>
     */
    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $query = Warehouse::query();

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $this->toBool($filters['is_active']));
        }

        return $this->applyListQuery($query, $filters);
    }

    public function create(array $data): Warehouse
    {
        if (Warehouse::query()->where('code', (string) $data['code'])->exists()) {
            throw ApiException::make('DUPLICATE_WAREHOUSE_CODE', 'Warehouse code is already in use.', 422, [
                'code' => ['Code is already in use.'],
            ]);
        }

        if (! empty($data['name']) && $this->nameIsTaken((string) $data['name'])) {
            throw ApiException::make('DUPLICATE_WAREHOUSE_NAME', 'Warehouse name is already in use.', 422, [
                'name' => ['Name is already in use.'],
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

        if (! empty($data['name']) && $this->nameIsTaken((string) $data['name'], $warehouse->id)) {
            throw ApiException::make('DUPLICATE_WAREHOUSE_NAME', 'Warehouse name is already in use.', 422, [
                'name' => ['Name is already in use.'],
            ]);
        }

        $warehouse->fill($data);
        $warehouse->save();

        if (array_key_exists('is_default', $data) && (bool) $data['is_default']) {
            $this->setDefault($warehouse);
        }

        return $warehouse->refresh();
    }

    /**
     * Nama dibandingkan tanpa memedulikan huruf besar/kecil dan spasi di ujung,
     * supaya "Gudang Utama" dan "gudang utama " tidak lolos sebagai dua gudang
     * berbeda -- dropdown gudang di modul lain menampilkan nama, bukan kode,
     * jadi nama kembar tidak bisa dibedakan user. Berbeda dengan `code`, tidak
     * ada unique index di DB untuk `name`, jadi guard ini hanya di aplikasi.
     */
    protected function nameIsTaken(string $name, ?int $ignoreId = null): bool
    {
        $query = Warehouse::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))]);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
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
