<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        // No unique DB constraint — NULL doesn't equal NULL in SQL.
        // Uniqueness on (submission_id, account_id, project_id, period) enforced in service.
        Schema::connection('tenant')->create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_submission_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->char('period', 7)->nullable();
            $table->decimal('amount', 20, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('budget_submission_id');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('budget_lines');
    }
};
