<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom tanggal dokumen dipakai sebagai sort default & filter periode di
     * `AppliesListQuery` (lihat `Finlite_knowladge/plans/list-query-pushdown`).
     * Tanpa index ini, memindahkan filter ke SQL justru memicu full table scan
     * setiap kali daftar diurutkan berdasarkan tanggal — modul dengan index
     * tanggal yang sudah ada (cash_receipts, cash_payments, bank_transfers,
     * stock_movements, stock_adjustments, stock_opnames, journal_entries)
     * sengaja tidak diulang di sini.
     *
     * @var array<string,string>
     */
    private array $dateColumns = [
        'sales_quotations' => 'quotation_date',
        'sales_orders' => 'order_date',
        'proforma_invoices' => 'proforma_date',
        'delivery_orders' => 'delivery_date',
        'sales_invoices' => 'invoice_date',
        'sales_returns' => 'return_date',
        'customer_deposits' => 'deposit_date',
        'sales_receipts' => 'receipt_date',
        'purchase_requests' => 'request_date',
        'purchase_orders' => 'order_date',
        'goods_receipts' => 'receipt_date',
        'vendor_bills' => 'bill_date',
        'purchase_returns' => 'return_date',
        'vendor_deposits' => 'deposit_date',
        'vendor_payments' => 'payment_date',
        // statement_end_date, bukan created_at — itu tanggal akhir periode
        // rekonsiliasi (diverifikasi 2026-08-06, lihat phase-4-cash-bank.md).
        'bank_reconciliations' => 'statement_end_date',
    ];

    public function up(): void
    {
        foreach ($this->dateColumns as $table => $column) {
            $indexName = "{$table}_{$column}_index";
            if (Schema::hasColumn($table, $column) && ! Schema::hasIndex($table, $indexName)) {
                Schema::table($table, function (Blueprint $blueprint) use ($column, $indexName) {
                    $blueprint->index($column, $indexName);
                });
            }
        }

        // purchase_requests adalah satu-satunya modul transaksi tanpa index
        // status (audit 2026-08-06) — filter status akan full-scan tanpa ini.
        $requestsStatusIndex = 'purchase_requests_status_index';
        if (Schema::hasColumn('purchase_requests', 'status') && ! Schema::hasIndex('purchase_requests', $requestsStatusIndex)) {
            Schema::table('purchase_requests', function (Blueprint $blueprint) use ($requestsStatusIndex) {
                $blueprint->index('status', $requestsStatusIndex);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->dateColumns as $table => $column) {
            $indexName = "{$table}_{$column}_index";
            if (Schema::hasIndex($table, $indexName)) {
                Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                    $blueprint->dropIndex($indexName);
                });
            }
        }

        $requestsStatusIndex = 'purchase_requests_status_index';
        if (Schema::hasIndex('purchase_requests', $requestsStatusIndex)) {
            Schema::table('purchase_requests', function (Blueprint $blueprint) use ($requestsStatusIndex) {
                $blueprint->dropIndex($requestsStatusIndex);
            });
        }
    }
};
