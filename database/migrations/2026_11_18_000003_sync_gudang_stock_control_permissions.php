<?php

use App\Shared\Permission\PermissionCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $catalog = app(PermissionCatalogService::class);
        foreach ((array) config('permissions.matrix_keys', []) as $index => $key) {
            if (str_starts_with($key, 'gudang.counts.')) {
                DB::table('permissions')->updateOrInsert(['key' => $key], $catalog->fromKey($key, $index + 1) + ['created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'like', 'gudang.counts.%')->delete();
    }
};
