<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id'); // pemilik (central user id)
            $table->string('report_key', 60);
            $table->string('name', 100);
            $table->json('params')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('report_key');
        });

        // Berbagi ke banyak user: sebuah saved report dapat dibagikan ke >1 user.
        Schema::connection('tenant')->create('saved_report_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saved_report_id')->constrained('saved_reports')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id'); // penerima (central user id)
            $table->timestamps();

            $table->unique(['saved_report_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('saved_report_shares');
        Schema::connection('tenant')->dropIfExists('saved_reports');
    }
};
