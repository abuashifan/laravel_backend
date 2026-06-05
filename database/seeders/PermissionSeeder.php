<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use App\Services\Permissions\PermissionCatalogService;
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

        $roleLabels = [
            'owner' => 'Owner',
            'admin' => 'Admin',
            'finance' => 'Finance Manager',
            'accountant' => 'Accountant',
            'sales' => 'Sales Admin',
            'purchasing' => 'Purchase Admin',
            'warehouse' => 'Warehouse Staff',
            'viewer' => 'Viewer',
        ];

        foreach ((array) config('permissions.roles', []) as $slug => $keys) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $roleLabels[$slug] ?? str($slug)->headline()->toString(),
                    'description' => 'System role preset. User permissions can still be overridden per company.',
                    'is_system' => true,
                    'is_active' => true,
                ]
            );

            $permissionIds = in_array('*', (array) $keys, true)
                ? Permission::query()->pluck('id')->all()
                : Permission::query()->whereIn('key', (array) $keys)->pluck('id')->all();

            $role->permissions()->sync($permissionIds);
        }

        if (DB::getSchemaBuilder()->hasColumn('company_users', 'role_id')) {
            Role::query()->get(['id', 'slug'])->each(function (Role $role): void {
                DB::table('company_users')
                    ->where('role', $role->slug)
                    ->whereNull('role_id')
                    ->update(['role_id' => $role->id]);
            });
        }
    }
}
