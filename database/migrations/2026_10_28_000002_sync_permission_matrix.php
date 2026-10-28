<?php

use App\Shared\Permission\PermissionCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $catalog = app(PermissionCatalogService::class);
        foreach ((array) config('permissions.matrix_keys', []) as $index => $key) {
            $values = $catalog->fromKey($key, $index + 1);
            unset($values['key']);
            $values['updated_at'] = now();

            if (DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->where('key', $key)->update($values);
            } else {
                DB::table('permissions')->insert(['key' => $key, ...$values, 'created_at' => now()]);
            }
        }

        if (Schema::hasTable('roles') && Schema::hasTable('role_permissions')) {
            $fullAccessRoles = DB::table('roles')->where('is_system', true)
                ->whereIn('slug', ['owner', 'admin'])->pluck('id');
            $matrixIds = DB::table('permissions')->whereIn('key', (array) config('permissions.matrix_keys', []))->pluck('id');
            foreach ($fullAccessRoles as $roleId) {
                foreach ($matrixIds as $permissionId) {
                    DB::table('role_permissions')->insertOrIgnore([
                        'role_id' => $roleId, 'permission_id' => $permissionId,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        $renames = (array) config('permission_renames', []);
        foreach ((array) config('permissions.retired_keys', []) as $oldKey) {
            $oldId = DB::table('permissions')->where('key', $oldKey)->value('id');
            if (! $oldId) {
                continue;
            }

            $newIds = DB::table('permissions')->whereIn('key', $renames[$oldKey] ?? [])->pluck('id');
            if (Schema::hasTable('role_permissions')) {
                $roleIds = DB::table('role_permissions')->where('permission_id', $oldId)->pluck('role_id');
                foreach ($roleIds as $roleId) {
                    foreach ($newIds as $newId) {
                        DB::table('role_permissions')->insertOrIgnore([
                            'role_id' => $roleId, 'permission_id' => $newId,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }

            if (Schema::hasTable('company_user_permission_overrides')) {
                $overrides = DB::table('company_user_permission_overrides')->where('permission_id', $oldId)->get();
                foreach ($overrides as $override) {
                    foreach ($newIds as $newId) {
                        $existing = DB::table('company_user_permission_overrides')
                            ->where('company_user_id', $override->company_user_id)
                            ->where('permission_id', $newId)->first();
                        if ($existing) {
                            if ($override->effect === 'deny' && $existing->effect !== 'deny') {
                                DB::table('company_user_permission_overrides')->where('id', $existing->id)
                                    ->update(['effect' => 'deny', 'updated_at' => now()]);
                            }

                            continue;
                        }
                        DB::table('company_user_permission_overrides')->insert([
                            'company_user_id' => $override->company_user_id, 'permission_id' => $newId,
                            'effect' => $override->effect, 'reason' => $override->reason,
                            'created_by' => $override->created_by, 'updated_by' => $override->updated_by,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }

            DB::table('permissions')->where('id', $oldId)->delete();
        }
    }

    public function down(): void
    {
        // Catalog cleanup and role assignments are not automatically reversed.
    }
};
