<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hapus transaksi (mode lock/open).
 *
 * Draft dihapus permanen. Transaksi yang sudah berefek (posted, dsb.) dihapus
 * dengan cara: efeknya dibatalkan lewat jalur void yang sudah ada, lalu
 * dokumennya ditandai terhapus (`deleted_at`) supaya hilang dari daftar --
 * jurnal lama yang sudah di-void tetap tersimpan sebagai jejak audit.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    /** @var list<string> */
    private array $tables = [
        'journal_entries',
        'sales_invoices',
        'sales_receipts',
        'vendor_bills',
        'vendor_payments',
        'cash_receipts',
        'cash_payments',
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
