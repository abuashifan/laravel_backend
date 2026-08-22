<?php

use App\Shared\Permission\PermissionCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `reports.save` dan `reports.multi_period` (Fase 2, skema tier) — dipisah dari
 * `reports.view` supaya laporan tersimpan dan banding multi-periode bisa
 * digerbangi lapis paket tanpa ikut menutup laporan standar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $keys = collect(['reports.save', 'reports.multi_period']);

        $catalog = app(PermissionCatalogService::class);

        foreach ($keys as $index => $key) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                array_merge($catalog->fromKey($key, 850 + $index), ['updated_at' => now(), 'created_at' => now()])
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
