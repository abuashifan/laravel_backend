<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('contact_code', 50)->nullable();
            $table->string('name');
            $table->string('contact_type', 20)->default('other');
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_supplier')->default(false);
            $table->boolean('is_employee')->default(false);
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('contact_code');
            $table->index('name');
            $table->index('contact_type');
            $table->index('is_customer');
            $table->index('is_supplier');
            $table->index('is_employee');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};

