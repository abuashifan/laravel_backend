<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->string('cash_flow_section', 20)->nullable()->default(null)->after('normal_balance');
            $table->index('cash_flow_section');
        });

        // Seed sensible defaults based on account_type:
        //   revenue / expense → operating (cash from/for operations)
        //   equity            → financing (owner contributions, dividends)
        //   asset / liability → null (ambiguous: could be AR/AP=operating or FA/debt=investing/financing)
        // Admins can fine-tune per-account via COA settings.
        DB::connection('tenant')->table('chart_of_accounts')
            ->whereIn('account_type', ['revenue', 'expense'])
            ->update(['cash_flow_section' => 'operating']);

        DB::connection('tenant')->table('chart_of_accounts')
            ->where('account_type', 'equity')
            ->update(['cash_flow_section' => 'financing']);
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropIndex(['cash_flow_section']);
            $table->dropColumn('cash_flow_section');
        });
    }
};
