<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Contacts created before is_customer/is_supplier were wired into the create/edit
     * form only ever got `contact_type` set — the boolean flags stayed false. Filtering
     * and customer/vendor pickers now read is_customer/is_supplier as the source of truth,
     * so backfill them from the legacy contact_type value to avoid losing existing contacts.
     */
    public function up(): void
    {
        DB::connection('tenant')->table('contacts')
            ->where('is_customer', false)
            ->where('is_supplier', false)
            ->where('contact_type', 'customer')
            ->update(['is_customer' => true]);

        DB::connection('tenant')->table('contacts')
            ->where('is_customer', false)
            ->where('is_supplier', false)
            ->where('contact_type', 'supplier')
            ->update(['is_supplier' => true]);
    }

    public function down(): void
    {
        // Data backfill only; not reversible.
    }
};
