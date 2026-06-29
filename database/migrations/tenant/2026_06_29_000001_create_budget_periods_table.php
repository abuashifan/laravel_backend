<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('budget_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->smallInteger('fiscal_year');
            $table->date('period_from');
            $table->date('period_to');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('budget_periods');
    }
};
