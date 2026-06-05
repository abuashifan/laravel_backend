<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->unsignedTinyInteger('precision')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('code');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};

