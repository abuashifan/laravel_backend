<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_terms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('days')->nullable();
            $table->boolean('is_custom')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
            $table->index('sort_order');
        });

        $now = now();
        DB::connection('tenant')->table('payment_terms')->insert([
            ['code' => 'COD', 'name' => 'COD', 'days' => 0, 'is_custom' => false, 'is_active' => true, 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'NET_7', 'name' => 'Net 7', 'days' => 7, 'is_custom' => false, 'is_active' => true, 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'NET_14', 'name' => 'Net 14', 'days' => 14, 'is_custom' => false, 'is_active' => true, 'sort_order' => 30, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'NET_30', 'name' => 'Net 30', 'days' => 30, 'is_custom' => false, 'is_active' => true, 'sort_order' => 40, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'NET_45', 'name' => 'Net 45', 'days' => 45, 'is_custom' => false, 'is_active' => true, 'sort_order' => 50, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'NET_60', 'name' => 'Net 60', 'days' => 60, 'is_custom' => false, 'is_active' => true, 'sort_order' => 60, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CUSTOM', 'name' => 'Custom', 'days' => null, 'is_custom' => true, 'is_active' => true, 'sort_order' => 70, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_terms');
    }
};
