<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }

        foreach (DB::table('plans')->select(['id', 'features'])->cursor() as $plan) {
            $features = json_decode((string) $plan->features, true);
            if (! is_array($features) || ! in_array('user_permission', $features, true)) {
                continue;
            }

            $features = array_values(array_filter(
                $features,
                static fn ($feature): bool => $feature !== 'user_permission'
            ));

            DB::table('plans')->where('id', $plan->id)->update([
                'features' => json_encode($features, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // The former tier gate is intentionally not restored.
    }
};
