<?php

use App\Shared\Permission\PermissionCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $key = 'gudang.receipts.create';
        $catalog = app(PermissionCatalogService::class);
        $index = array_search($key, config('permissions.matrix_keys'), true);
        DB::table('permissions')->insertOrIgnore($catalog->fromKey($key, $index + 1) + [
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'gudang.receipts.create')->delete();
    }
};
