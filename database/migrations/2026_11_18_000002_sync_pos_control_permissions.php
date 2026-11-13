<?php

use App\Shared\Permission\PermissionCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Fase 4b: daftarkan izin log aktivitas dan diskon di atas batas. */
return new class extends Migration
{
    private const KEYS = ['pos.audit.view', 'pos.discount.override'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $catalog = app(PermissionCatalogService::class);
        foreach ((array) config('permissions.matrix_keys', []) as $index => $key) {
            if (! in_array($key, self::KEYS, true)) {
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
            DB::table('permissions')->whereIn('key', self::KEYS)->delete();
        }
    }
};
