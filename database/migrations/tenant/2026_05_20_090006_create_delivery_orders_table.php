<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_number')->unique();
            $table->date('delivery_date');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->string('sales_order_number')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('status', 30)->default('draft');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_number')->nullable();
            $table->unsignedInteger('source_revision')->nullable();
            $table->unsignedInteger('revision_no')->default(1);
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('ready_by')->nullable();
            $table->unsignedBigInteger('shipped_by')->nullable();
            $table->unsignedBigInteger('delivered_by')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->text('void_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'delivery_date']);
            $table->index('sales_order_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_orders');
    }
};
