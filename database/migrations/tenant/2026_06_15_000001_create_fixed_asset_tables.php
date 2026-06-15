<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('asset_class', 30);
            $table->string('depreciation_type', 30);
            $table->unsignedSmallInteger('default_useful_life_years')->nullable();
            $table->foreignId('asset_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('accumulated_depreciation_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('depreciation_expense_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('clearing_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('disposal_gain_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('disposal_loss_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['asset_class', 'depreciation_type']);
            $table->index('is_active');
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_number')->nullable()->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('fixed_asset_category_id')->constrained('fixed_asset_categories')->restrictOnDelete();
            $table->string('asset_class', 30);
            $table->string('depreciation_type', 30);
            $table->string('depreciation_method', 30)->default('straight_line');
            $table->string('status', 30)->default('draft');
            $table->date('acquisition_date');
            $table->date('service_start_date')->nullable();
            $table->string('first_depreciation_period', 7)->nullable();
            $table->string('last_depreciation_period', 7)->nullable();
            $table->unsignedSmallInteger('useful_life_years')->nullable();
            $table->unsignedSmallInteger('useful_life_months')->nullable();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('remaining_quantity', 18, 4)->default(1);
            $table->decimal('unit_acquisition_cost', 18, 2)->default(0);
            $table->decimal('acquisition_cost', 18, 2)->default(0);
            $table->decimal('salvage_value', 18, 2)->default(0);
            $table->decimal('depreciable_basis', 18, 2)->default(0);
            $table->decimal('accumulated_depreciation', 18, 2)->default(0);
            $table->decimal('net_book_value', 18, 2)->default(0);
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('capitalized_at')->nullable();
            $table->timestamp('disposed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['status', 'asset_class']);
            $table->index(['source_type', 'source_id']);
            $table->index('first_depreciation_period');
        });

        Schema::create('fixed_asset_acquisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->date('acquisition_date');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('capitalized_amount', 18, 2)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['source_type', 'source_id', 'source_line_id']);
        });

        Schema::create('fixed_asset_depreciation_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('period', 7);
            $table->decimal('depreciation_amount', 18, 2)->default(0);
            $table->decimal('accumulated_depreciation_after', 18, 2)->default(0);
            $table->decimal('net_book_value_after', 18, 2)->default(0);
            $table->string('status', 30)->default('scheduled');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['fixed_asset_id', 'period']);
            $table->index(['period', 'status']);
        });

        Schema::create('fixed_asset_depreciation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_number')->unique();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('period', 7);
            $table->string('status', 30)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['period', 'status']);
        });

        Schema::create('fixed_asset_depreciation_run_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_depreciation_run_id')->constrained('fixed_asset_depreciation_runs')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->foreignId('fixed_asset_depreciation_schedule_id')->constrained('fixed_asset_depreciation_schedules')->cascadeOnDelete();
            $table->decimal('depreciation_amount', 18, 2)->default(0);
            $table->decimal('accumulated_depreciation_after', 18, 2)->default(0);
            $table->decimal('net_book_value_after', 18, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('fixed_asset_disposals', function (Blueprint $table) {
            $table->id();
            $table->string('disposal_number')->unique();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->date('disposal_date');
            $table->string('disposal_type', 30);
            $table->decimal('disposed_quantity', 18, 4);
            $table->decimal('disposal_cost_amount', 18, 2)->default(0);
            $table->decimal('disposal_accumulated_depreciation_amount', 18, 2)->default(0);
            $table->decimal('disposal_net_book_value', 18, 2)->default(0);
            $table->decimal('proceeds_amount', 18, 2)->default(0);
            $table->decimal('gain_loss_amount', 18, 2)->default(0);
            $table->foreignId('cash_bank_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('receivable_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['fixed_asset_id', 'disposal_date']);
        });

        Schema::create('fixed_asset_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->string('transaction_type', 40);
            $table->date('transaction_date');
            $table->string('period', 7)->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('quantity', 18, 4)->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['transaction_type', 'transaction_date']);
            $table->index(['source_type', 'source_id']);
        });

        $now = now();
        DB::table('fixed_asset_categories')->insert(array_map(fn (array $row): array => array_merge($row, [
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]), [
            ['code' => 'LAND', 'name' => 'Tanah', 'asset_class' => 'tangible', 'depreciation_type' => 'none', 'default_useful_life_years' => null],
            ['code' => 'BUILDING', 'name' => 'Bangunan', 'asset_class' => 'tangible', 'depreciation_type' => 'depreciation', 'default_useful_life_years' => 20],
            ['code' => 'VEHICLE', 'name' => 'Kendaraan', 'asset_class' => 'tangible', 'depreciation_type' => 'depreciation', 'default_useful_life_years' => 8],
            ['code' => 'MACHINE', 'name' => 'Mesin dan Peralatan Produksi', 'asset_class' => 'tangible', 'depreciation_type' => 'depreciation', 'default_useful_life_years' => 8],
            ['code' => 'OFFICE_EQUIP', 'name' => 'Peralatan Kantor', 'asset_class' => 'tangible', 'depreciation_type' => 'depreciation', 'default_useful_life_years' => 4],
            ['code' => 'IT_EQUIP', 'name' => 'Komputer dan Perangkat IT', 'asset_class' => 'tangible', 'depreciation_type' => 'depreciation', 'default_useful_life_years' => 4],
            ['code' => 'FURNITURE', 'name' => 'Furniture dan Fixture', 'asset_class' => 'tangible', 'depreciation_type' => 'depreciation', 'default_useful_life_years' => 4],
            ['code' => 'LEASEHOLD', 'name' => 'Renovasi / Leasehold Improvement', 'asset_class' => 'tangible', 'depreciation_type' => 'depreciation', 'default_useful_life_years' => 8],
            ['code' => 'CIP', 'name' => 'Aset Dalam Penyelesaian', 'asset_class' => 'tangible', 'depreciation_type' => 'none', 'default_useful_life_years' => null],
            ['code' => 'SOFTWARE', 'name' => 'Software', 'asset_class' => 'intangible', 'depreciation_type' => 'amortization', 'default_useful_life_years' => 4],
            ['code' => 'PATENT', 'name' => 'Hak Paten', 'asset_class' => 'intangible', 'depreciation_type' => 'amortization', 'default_useful_life_years' => 8],
            ['code' => 'COPYRIGHT', 'name' => 'Copyright / Hak Cipta', 'asset_class' => 'intangible', 'depreciation_type' => 'amortization', 'default_useful_life_years' => 8],
            ['code' => 'GOODWILL', 'name' => 'Goodwill', 'asset_class' => 'intangible', 'depreciation_type' => 'impairment_only', 'default_useful_life_years' => null],
            ['code' => 'TRADEMARK', 'name' => 'Merek Dagang / Trademark', 'asset_class' => 'intangible', 'depreciation_type' => 'amortization', 'default_useful_life_years' => 8],
            ['code' => 'OTHER', 'name' => 'Aset Lainnya', 'asset_class' => 'tangible', 'depreciation_type' => 'depreciation', 'default_useful_life_years' => 4],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_transactions');
        Schema::dropIfExists('fixed_asset_disposals');
        Schema::dropIfExists('fixed_asset_depreciation_run_lines');
        Schema::dropIfExists('fixed_asset_depreciation_runs');
        Schema::dropIfExists('fixed_asset_depreciation_schedules');
        Schema::dropIfExists('fixed_asset_acquisitions');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('fixed_asset_categories');
    }
};
