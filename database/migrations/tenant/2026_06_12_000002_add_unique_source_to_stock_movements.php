<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::connection('tenant')
            ->table('stock_movements')
            ->select('source_type', 'source_id', 'movement_type')
            ->selectRaw('COUNT(*) as duplicate_count')
            ->whereNotNull('source_type')
            ->whereNotNull('source_id')
            ->groupBy('source_type', 'source_id', 'movement_type')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new RuntimeException(sprintf(
                'Duplicate stock movement source found for source_type=%s, source_id=%s, movement_type=%s (%s rows). Resolve duplicates before adding stock_movements_source_unique.',
                $duplicate->source_type,
                $duplicate->source_id,
                $duplicate->movement_type,
                $duplicate->duplicate_count
            ));
        }

        Schema::connection('tenant')->table('stock_movements', function (Blueprint $table) {
            $table->unique(['source_type', 'source_id', 'movement_type'], 'stock_movements_source_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('stock_movements', function (Blueprint $table) {
            $table->dropUnique('stock_movements_source_unique');
        });
    }
};
