<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_bill_lines', function (Blueprint $table) {
            $table->string('line_classification', 30)->nullable()->after('vendor_bill_id');
            $table->foreignId('fixed_asset_category_id')->nullable()->after('expense_account_id')->constrained('fixed_asset_categories')->nullOnDelete();
            $table->decimal('capitalized_amount', 18, 2)->default(0)->after('fixed_asset_category_id');
            $table->index('line_classification');
            $table->index('fixed_asset_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_bill_lines', function (Blueprint $table) {
            $table->dropForeign(['fixed_asset_category_id']);
            $table->dropIndex(['line_classification']);
            $table->dropIndex(['fixed_asset_category_id']);
            $table->dropColumn(['line_classification', 'fixed_asset_category_id', 'capitalized_amount']);
        });
    }
};
