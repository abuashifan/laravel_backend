<?php

use App\Shared\Permission\PermissionCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_budget_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->date('period_from');
            $table->date('period_to');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['company_id', 'period_from']);
        });

        $catalog = app(PermissionCatalogService::class);
        foreach (config('permissions.matrix_keys', []) as $index => $key) {
            if (str_starts_with($key, 'workspace.budget.')) {
                DB::table('permissions')->updateOrInsert(['key' => $key], $catalog->fromKey($key, $index + 1) + [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'like', 'workspace.budget.%')->delete();
        Schema::dropIfExists('workspace_budget_periods');
    }
};
