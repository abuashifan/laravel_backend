<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'gudang';

    public function up(): void
    {
        $schema = Schema::connection('gudang');
        $schema->create('gudang_number_sequences', function (Blueprint $t) {
            $t->string('type', 40)->primary();
            $t->unsignedBigInteger('last_number')->default(0);
        });
        $schema->table('gudang_stock_documents', function (Blueprint $t) {
            $t->unsignedBigInteger('serial')->nullable();
            $t->unique(['type', 'serial']);
        });
        $db = DB::connection('gudang');
        $db->table('gudang_stock_documents')->update(['serial' => DB::raw('id')]);
        foreach ($db->table('gudang_stock_documents')->groupBy('type')->selectRaw('type,max(serial) as last_number')->get() as $row) {
            $db->table('gudang_number_sequences')->insert((array) $row);
        }
    }

    public function down(): void
    {
        Schema::connection('gudang')->table('gudang_stock_documents', function (Blueprint $t) {
            $t->dropUnique(['type', 'serial']);
            $t->dropColumn('serial');
        });
        Schema::connection('gudang')->dropIfExists('gudang_number_sequences');
    }
};
