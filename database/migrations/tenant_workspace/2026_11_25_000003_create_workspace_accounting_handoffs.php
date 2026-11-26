<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_accounting_handoffs', function (Blueprint $table): void {
            $table->foreignId('document_id')->primary()->constrained('workspace_budget_documents');
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error', 150)->nullable();
        });

        DB::table('workspace_accounting_handoffs')->insertUsing(
            ['document_id'],
            DB::table('workspace_budget_documents')->where('kind', 'activity')->where('status', 'approved')->select('id'),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_accounting_handoffs');
    }
};
