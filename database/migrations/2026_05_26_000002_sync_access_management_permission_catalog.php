<?php

use App\Services\Permissions\PermissionCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $catalog = app(PermissionCatalogService::class);
        $keys = collect((array) config('permissions.permissions', []))
            ->filter(fn (string $key): bool => str_starts_with($key, 'access.'))
            ->values();

        foreach ($keys as $index => $key) {
            $permission = $catalog->fromKey($key, 1000 + $index);
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                array_merge($permission, ['updated_at' => now(), 'created_at' => now()])
            );
        }

        $roleIds = DB::table('roles')
            ->where('is_system', true)
            ->whereIn('slug', ['owner', 'admin'])
            ->pluck('id');
        $permissionIds = DB::table('permissions')->whereIn('key', $keys)->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
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
