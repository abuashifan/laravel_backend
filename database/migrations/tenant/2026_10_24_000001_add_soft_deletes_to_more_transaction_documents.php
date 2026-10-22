<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hapus transaksi tahap 2 (mode lock/open) -- sama dengan
 * `2026_10_23_000001_add_soft_deletes_to_transaction_documents`: dokumen yang
 * sudah berefek dihapus dengan cara di-void lalu ditandai terhapus.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    /** @var list<string> */
    private array $tables = [
        'sales_returns',
        'purchase_returns',
        'customer_deposits',
        'vendor_deposits',
        'bank_transfers',
        'goods_receipts',
        'delivery_orders',
        'stock_adjustments',
        'stock_movements',
        'stock_opnames',
    ];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::connection('tenant')->table($name, function (Blueprint $table) {
                $table->softDeletes();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->text('delete_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::connection('tenant')->table($name, function (Blueprint $table) {
                $table->dropColumn(['deleted_at', 'deleted_by', 'delete_reason']);
            });
        }
    }
};
