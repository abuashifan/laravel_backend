<?php

namespace Tests\Feature\Permissions;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\User;
use App\Shared\Permission\PermissionCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_has_ten_ordered_modules_and_all_seven_columns(): void
    {
        $catalog = app(PermissionCatalogService::class)->grouped();

        $this->assertSame(['daftar', 'tambah', 'ubah', 'hapus', 'persetujuan', 'cetak', 'ekspor'], $catalog['matrix_columns']);
        $this->assertSame(
            ['general', 'master_data', 'accounting', 'budget', 'cash_bank', 'sales', 'purchase', 'inventory', 'fixed_assets', 'reports'],
            array_column($catalog['modules'], 'key')
        );
        $reports = collect($catalog['modules'])->firstWhere('key', 'reports');
        $this->assertCount(13, $reports['features']);
        $this->assertSame('reports.financial.export', $reports['features'][0]['permissions']['ekspor']['key']);
        $cash = collect($catalog['modules'])->firstWhere('key', 'cash_bank');
        $this->assertSame('cash_bank.receipts.approve', $cash['features'][0]['permissions']['persetujuan']['key']);
    }

    public function test_legacy_mapping_covers_the_old_catalog_and_targets_real_matrix_keys(): void
    {
        $all = config('permissions.legacy_keys');
        $new = config('permissions.matrix_keys');
        $renames = config('permission_renames');

        $this->assertSame([], array_values(array_diff($all, $new, array_keys($renames))));
        foreach ($renames as $old => $targets) {
            foreach ($targets as $target) {
                $this->assertContains($target, $new, "Invalid mapping from {$old}");
            }
        }
        $this->assertContains('cash_bank.receipts.view', $renames['cash_bank.view']);
        $this->assertContains('reports.tax.view', $renames['reports.view']);
        $this->assertNotContains('inventory.adjustment.create', config('permissions.permissions'));
    }

    public function test_sync_migration_creates_new_keys_and_updates_existing_metadata(): void
    {
        DB::table('permissions')->where('key', 'cash_bank.receipts.view')->delete();
        DB::table('permissions')->where('key', 'dashboard.financial.view')->update(['module' => 'dashboard']);
        $ownerRole = DB::table('roles')->insertGetId([
            'name' => 'Owner', 'slug' => 'owner', 'is_system' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_10_28_000002_sync_permission_matrix.php');
        $migration->up();
        $migration->up();

        $this->assertDatabaseHas('permissions', [
            'key' => 'cash_bank.receipts.view', 'module' => 'cash_bank',
            'feature' => 'cash_bank.receipts', 'matrix_column' => 'daftar',
        ]);
        $this->assertDatabaseHas('permissions', [
            'key' => 'dashboard.financial.view', 'module' => 'general', 'is_special' => true,
        ]);
        $this->assertSame(1, DB::table('permissions')->where('key', 'cash_bank.receipts.view')->count());
        $permissionId = DB::table('permissions')->where('key', 'cash_bank.receipts.view')->value('id');
        $this->assertDatabaseHas('role_permissions', ['role_id' => $ownerRole, 'permission_id' => $permissionId]);
    }

    public function test_sync_migration_replaces_retired_role_and_override_permissions(): void
    {
        $catalog = app(PermissionCatalogService::class);
        $old = $catalog->fromKey('inventory.adjustment.create');
        $oldId = DB::table('permissions')->insertGetId([...$old, 'created_at' => now(), 'updated_at' => now()]);
        $newId = DB::table('permissions')->where('key', 'inventory.adjustments.create')->value('id');
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Test', 'slug' => 'matrix-test', 'is_system' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('role_permissions')->insert([
            'role_id' => $roleId, 'permission_id' => $oldId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::factory()->create();
        $company = Company::query()->create([
            'name' => 'Matrix', 'slug' => 'matrix', 'code' => 'MATRIX', 'status' => 'active', 'created_by' => $user->id,
        ]);
        $membership = CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id, 'role' => 'viewer', 'status' => 'active',
        ]);
        foreach ([[$oldId, 'deny'], [$newId, 'allow']] as [$id, $effect]) {
            DB::table('company_user_permission_overrides')->insert([
                'company_user_id' => $membership->id, 'permission_id' => $id, 'effect' => $effect,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $migration = require database_path('migrations/2026_10_28_000002_sync_permission_matrix.php');
        $migration->up();

        $this->assertDatabaseMissing('permissions', ['key' => 'inventory.adjustment.create']);
        $this->assertDatabaseHas('role_permissions', ['role_id' => $roleId, 'permission_id' => $newId]);
        $this->assertDatabaseHas('company_user_permission_overrides', [
            'company_user_id' => $membership->id, 'permission_id' => $newId, 'effect' => 'deny',
        ]);
    }
}
