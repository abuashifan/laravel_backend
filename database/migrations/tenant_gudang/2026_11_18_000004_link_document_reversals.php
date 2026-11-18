<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'gudang';

    public function up(): void
    {
        Schema::connection($this->connection)->table('gudang_stock_documents', function (Blueprint $table) {
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('gudang_stock_documents');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('gudang_stock_documents', function (Blueprint $table) {
            $table->dropUnique(['reverses_id']);
            $table->dropConstrainedForeignId('reverses_id');
        });
    }
};
