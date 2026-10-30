<?php

namespace Database\Seeders;

use App\Shared\Models\Permission;
use App\Shared\Permission\PermissionCatalogService;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = app(PermissionCatalogService::class);

        foreach ((array) config('permissions.permissions', []) as $index => $key) {
            Permission::query()->updateOrCreate(
                ['key' => $key],
                $catalog->fromKey($key, $index + 1)
            );
        }
    }
}
