<?php

namespace Tests\Feature\Permissions;

use App\Shared\Permission\PermissionCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    }

    /**
     * Tabel peran & override sudah dihapus (Fase 8); migrasi sinkron tetap aman
     * dijalankan ulang dan tetap membuang kunci pensiun dari katalog.
     */
    public function test_sync_migration_removes_retired_keys_without_legacy_role_tables(): void
    {
        $this->assertFalse(Schema::hasTable('roles'));
        $this->assertFalse(Schema::hasTable('company_user_permission_overrides'));
        $old = app(PermissionCatalogService::class)->fromKey('inventory.adjustment.create');
        DB::table('permissions')->insert([...$old, 'created_at' => now(), 'updated_at' => now()]);

        $migration = require database_path('migrations/2026_10_28_000002_sync_permission_matrix.php');
        $migration->up();

        $this->assertDatabaseMissing('permissions', ['key' => 'inventory.adjustment.create']);
        $this->assertDatabaseHas('permissions', ['key' => 'inventory.adjustments.create']);
    }
}
