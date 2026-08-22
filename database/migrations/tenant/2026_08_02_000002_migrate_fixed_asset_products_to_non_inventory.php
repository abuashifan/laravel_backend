<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `fixed_asset` product_type never connected to any live transaction flow
     * (fixed asset purchases are classified per-line in Vendor Bill via
     * fixed_asset_category_id, independent of the product catalog). Existing
     * rows are reassigned to `non_inventory` — the closest equivalent
     * (non-stock item) — before the enum is tightened to drop the option.
     */
    public function up(): void
    {
        DB::connection('tenant')->table('products')
            ->where('product_type', 'fixed_asset')
            ->update(['product_type' => 'non_inventory']);
    }

    public function down(): void
    {
        // Not reversible: original fixed_asset rows can no longer be distinguished
        // from genuine non_inventory rows once merged.
    }
};
