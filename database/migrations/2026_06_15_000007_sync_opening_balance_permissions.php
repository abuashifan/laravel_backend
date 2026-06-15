<?php

use App\Services\Permissions\PermissionCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $keys = collect((array) config('permissions.permissions', []))
            ->filter(fn (string $key): bool => str_starts_with($key, 'opening_balance.'))
            ->values();

        $catalog = app(PermissionCatalogService::class);

        foreach ($keys as $index => $key) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                array_merge($catalog->fromKey($key, 800 + $index), ['updated_at' => now(), 'created_at' => now()])
            );
        }

        $permissionIdsByKey = DB::table('permissions')->whereIn('key', $keys)->pluck('id', 'key');
        foreach ((array) config('permissions.roles', []) as $slug => $rolePermissions) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if (! $roleId) {
                continue;
            }

            $assignedKeys = in_array('*', $rolePermissions, true) ? $keys : $keys->intersect($rolePermissions)->values();
            foreach ($assignedKeys as $key) {
                $permissionId = $permissionIdsByKey[$key] ?? null;
                if (! $permissionId) {
                    continue;
                }
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Permission catalog sync is additive; retaining rows preserves assigned access history.
    }
};
