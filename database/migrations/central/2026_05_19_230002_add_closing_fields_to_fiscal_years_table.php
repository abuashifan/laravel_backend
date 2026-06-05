<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            if (! Schema::hasColumn('fiscal_years', 'is_closed')) {
                $table->boolean('is_closed')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('fiscal_years', 'reopened_at')) {
                $table->timestamp('reopened_at')->nullable()->after('closed_at');
            }
            if (! Schema::hasColumn('fiscal_years', 'locked_until')) {
                $table->date('locked_until')->nullable()->after('reopened_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            if (Schema::hasColumn('fiscal_years', 'locked_until')) {
                $table->dropColumn('locked_until');
            }
            if (Schema::hasColumn('fiscal_years', 'reopened_at')) {
                $table->dropColumn('reopened_at');
            }
            if (Schema::hasColumn('fiscal_years', 'is_closed')) {
                $table->dropColumn('is_closed');
            }
        });
    }
};

