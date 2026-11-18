<?php

use App\Shared\Permission\PermissionCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const KEYS = ['gudang.issues.create','gudang.transfers.create','gudang.stock.approve'];
    public function up(): void
    {
        foreach (self::KEYS as $key) {
            $index = array_search($key, config('permissions.matrix_keys'), true);
            DB::table('permissions')->insertOrIgnore(app(PermissionCatalogService::class)->fromKey($key, $index + 1) + [
                'created_at'=>now(), 'updated_at'=>now(),
            ]);
        }
    }
    public function down(): void
    {
        DB::table('permissions')->whereIn('key',self::KEYS)->delete();
    }
};
