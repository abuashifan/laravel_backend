<?php

use App\Shared\Models\CompanyUser;
use App\Shared\Permission\LegacyAccessMigrationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_user_permissions')) {
            return;
        }

        $service = app(LegacyAccessMigrationService::class);
        CompanyUser::query()->orderBy('id')->chunkById(100, function ($members) use ($service): void {
            foreach ($members as $member) {
                $service->migrate($member);
            }
        });
    }

    public function down(): void
    {
        // Data direct dapat berubah setelah migrasi; rollback tidak menghapusnya.
    }
};
