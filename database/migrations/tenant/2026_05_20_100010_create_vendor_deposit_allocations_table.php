<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_deposit_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_deposit_id');
            $table->unsignedBigInteger('vendor_bill_id');
            $table->date('allocation_date');
            $table->decimal('allocated_amount', 18, 2);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('status', 30)->default('posted');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index('vendor_deposit_id');
            $table->index('vendor_bill_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_deposit_allocations');
    }
};
