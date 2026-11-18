<?php

namespace App\Modules\Gudang;

use App\Shared\Tenant\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class WarehouseAccess
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function owner(): bool
    {
        return (int) $this->tenant->company()->created_by === (int) auth()->id();
    }

    public function warehouses(): Builder
    {
        $db = DB::connection('gudang');
        $warehouses = $db->table('gudang_warehouses')->select('id');
        if (! $this->owner()) {
            $warehouses->whereIn('id', $db->table('gudang_members')->select('warehouse_id')->where('user_id', auth()->id()));
        }
        return $warehouses;
    }

    public function locations(): Builder
    {
        return DB::connection('gudang')->table('gudang_locations')->select('id')->whereIn('warehouse_id', $this->warehouses());
    }

    public function requireLocations(array $ids): void
    {
        $ids = array_unique(array_map('intval', $ids));
        abort_unless($this->locations()->whereIn('id', $ids)->count() === count($ids), 403, 'Lokasi di luar akses Anda.');
    }
}
