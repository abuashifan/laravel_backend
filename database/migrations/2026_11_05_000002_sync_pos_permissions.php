<?php

use App\Shared\Permission\PermissionCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Mendaftarkan kunci izin `pos.*` dari permission_matrix ke tabel permissions. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $catalog = app(PermissionCatalogService::class);
        foreach ((array) config('permissions.matrix_keys', []) as $index => $key) {
            if (! str_starts_with((string) $key, 'pos.')) {
                continue;
            }

            $values = $catalog->fromKey($key, $index + 1);
            unset($values['key']);
            $values['updated_at'] = now();

            if (DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->where('key', $key)->update($values);
            } else {
                DB::table('permissions')->insert(['key' => $key, ...$values, 'created_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('key', 'like', 'pos.%')->delete();
        }
    }
};
