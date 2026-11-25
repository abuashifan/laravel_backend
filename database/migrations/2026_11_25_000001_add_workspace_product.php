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
            if (str_starts_with($key, 'workspace.')) {
                DB::table('permissions')->updateOrInsert(['key' => $key], $catalog->fromKey($key, $index + 1) + [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('plans')->insert([
            'code' => 'workspace_pilot',
            'product' => 'workspace',
            'name' => 'Workspace Pilot',
            'description' => 'Paket Workspace untuk pilot yang ditetapkan oleh admin Finlite.',
            'max_users' => 25,
            'max_companies' => 1,
            'monthly_price' => 0,
            'yearly_price' => 0,
            'status' => 'active',
            'features' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'like', 'workspace.%')->delete();
        $plans = DB::table('plans')->where('code', 'workspace_pilot');
        DB::table('subscriptions')->whereIn('plan_id', (clone $plans)->select('id'))->delete();
        $plans->delete();
    }
};
