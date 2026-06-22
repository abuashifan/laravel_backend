<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_reconciliations', function (Blueprint $table) {
            $table->unsignedBigInteger('reopened_by')->nullable()->after('posted_at');
            $table->timestamp('reopened_at')->nullable()->after('reopened_by');
            $table->text('reopen_reason')->nullable()->after('reopened_at');
        });
    }

    public function down(): void
    {
        Schema::table('bank_reconciliations', function (Blueprint $table) {
            $table->dropColumn(['reopened_by', 'reopened_at', 'reopen_reason']);
        });
    }
};
