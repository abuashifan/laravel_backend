<?php

namespace App\Modules\Reports\Services;

use App\Modules\Inventory\Models\StockMovementLine;
use App\Modules\MasterData\Models\Product;
use App\Modules\Purchase\Models\PurchaseReturnLine;
use App\Modules\Purchase\Models\VendorBillLine;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturnLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Riwayat transaksi komersial satu produk: penjualan + pembelian, satu baris
 * per baris dokumen, lengkap dengan lawan transaksi dan harga.
 *
 * Melengkapi Kartu Stok (`Inventory\Services\Reports\StockCardReportService`),
 * bukan menggantikannya: Kartu Stok menjawab pergerakan dan nilai persediaan
 * (qty, HPP, saldo berjalan), laporan ini menjawab sisi komersialnya (dijual ke
 * siapa, dibeli dari siapa, dengan harga berapa).
 *
 * Diletakkan di level atas `Services/` -- bukan di `Sales/` atau `Purchase/` --
 * karena isinya lintas kedua domain, sama seperti `ReconciliationReportService`.
 */
class ProductHistoryReportService
{
    /**
     * Empat sumber baris, dinormalkan ke bentuk yang sama.
     *
     * `sign` mengikuti arah persediaan: faktur penjualan dan retur pembelian
     * mengurangi stok, tagihan vendor dan retur penjualan menambahnya.
     *
     * @var list<array{model: class-string, header_table: string, header_key: string, date_column: string, number_column: string, contact_column: string, type: string, direction: string, sign: int}>
     */
    private const SOURCES = [
        [
            'model' => SalesInvoiceLine::class,
            'header_table' => 'sales_invoices',
            'header_key' => 'sales_invoice_id',
            'date_column' => 'invoice_date',
            'number_column' => 'invoice_number',
            'contact_column' => 'customer_id',
            'type' => 'sales_invoice',
            'direction' => 'out',
            'sign' => -1,
        ],
        [
            'model' => SalesReturnLine::class,
            'header_table' => 'sales_returns',
            'header_key' => 'sales_return_id',
            'date_column' => 'return_date',
            'number_column' => 'return_number',
            'contact_column' => 'customer_id',
            'type' => 'sales_return',
            'direction' => 'in',
            'sign' => 1,
        ],
        [
            'model' => VendorBillLine::class,
            'header_table' => 'vendor_bills',
            'header_key' => 'vendor_bill_id',
            'date_column' => 'bill_date',
            'number_column' => 'bill_number',
            'contact_column' => 'vendor_id',
            'type' => 'vendor_bill',
            'direction' => 'in',
            'sign' => 1,
        ],
        [
            'model' => PurchaseReturnLine::class,
            'header_table' => 'purchase_returns',
            'header_key' => 'purchase_return_id',
            'date_column' => 'return_date',
            'number_column' => 'return_number',
            'contact_column' => 'vendor_id',
            'type' => 'purchase_return',
            'direction' => 'out',
            'sign' => -1,
        ],
    ];

    /**
     * Pergerakan stok yang sudah diwakili keempat dokumen komersial di atas,
     * atau oleh dokumen di rantai yang sama.
     *
     * Tanpa pengecualian ini satu penjualan lewat Surat Jalan lalu Faktur akan
     * muncul dua kali dengan kuantitas yang sama: sekali dari baris faktur,
     * sekali lagi dari pergerakan stok Surat Jalan-nya.
     *
     * Sengaja daftar-buang, bukan daftar-izin: jenis sumber baru (mis. transfer
     * antar gudang) langsung ikut tampil, bukan hilang diam-diam.
     */
    /**
     * Jenis dokumen yang berasal dari pergerakan stok, bukan transaksi
     * komersial. Dipakai `totals()` untuk memisahkannya dari beli/jual.
     * Harus sinkron dengan `movementDocumentType()`.
     */
    private const INVENTORY_DOCUMENT_TYPES = [
        'stock_adjustment',
        'stock_opname',
        'stock_transfer',
        'opening_balance',
        'stock_movement',
    ];

    private const MOVEMENT_SOURCES_ALREADY_COVERED = [
        'sales_invoice',
        'sales_return',
        'vendor_bill',
        'purchase_return',
        'delivery_order',
        'goods_receipt',
    ];

    /**
     * @param  array{product_id: int, start_date?: string|null, end_date?: string|null, department_id?: int|null, project_id?: int|null}  $filters
     * @return array{product: array<string,mixed>|null, rows: list<array<string,mixed>>, totals: array<string,float>}
     */
    public function getReport(array $filters): array
    {
        $rows = [];

        foreach (self::SOURCES as $source) {
            foreach ($this->fetchSource($source, $filters) as $row) {
                $rows[] = $row;
            }
        }

        foreach ($this->fetchInventoryMovements($filters) as $row) {
            $rows[] = $row;
        }

        // Digabung dan diurut di PHP, bukan lewat SQL UNION: nama kolom berbeda
        // di keempat tabel sehingga UNION-nya penuh alias, sementara jumlah
        // barisnya tertahan karena `product_id` wajib (lihat request).
        usort($rows, function (array $a, array $b): int {
            return [$a['date'], $a['document_number']] <=> [$b['date'], $b['document_number']];
        });

        // Produk ikut dikirim supaya judul laporan dan filter bisa menampilkan
        // namanya tanpa permintaan kedua -- dan tetap benar setelah halaman
        // dimuat ulang atau laporan dibuka dari daftar tersimpan, di mana klien
        // hanya punya `product_id`.
        $product = Product::query()->find((int) $filters['product_id']);

        return [
            'product' => $product === null ? null : [
                'id' => (int) $product->id,
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
            ],
            'rows' => $rows,
            'totals' => $this->totals($rows),
        ];
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $filters
     * @return list<array<string,mixed>>
     */
    private function fetchSource(array $source, array $filters): array
    {
        /** @var class-string<Model> $model */
        $model = $source['model'];
        $lines = (new $model)->getTable();
        $header = $source['header_table'];

        $query = $model::query()
            ->join("{$header} as hdr", 'hdr.id', '=', "{$lines}.{$source['header_key']}")
            ->leftJoin('contacts as ctc', 'ctc.id', '=', "hdr.{$source['contact_column']}")
            ->leftJoin('departments as dpt', 'dpt.id', '=', "{$lines}.department_id")
            ->leftJoin('projects as prj', 'prj.id', '=', "{$lines}.project_id")
            ->where('hdr.status', 'posted')
            ->where("{$lines}.product_id", (int) $filters['product_id']);

        // Rentang tanggal dikenakan pada kolom tanggal dokumen masing-masing,
        // bukan `created_at` -- dokumen bertanggal mundur harus jatuh di
        // periode transaksinya, bukan periode input.
        $this->applyDateRange($query, "hdr.{$source['date_column']}", $filters);

        if (! empty($filters['department_id'])) {
            $query->where("{$lines}.department_id", (int) $filters['department_id']);
        }
        if (! empty($filters['project_id'])) {
            $query->where("{$lines}.project_id", (int) $filters['project_id']);
        }

        return $query
            ->selectRaw("
                hdr.id as document_id,
                hdr.{$source['date_column']} as document_date,
                hdr.{$source['number_column']} as document_number,
                ctc.name as contact_name,
                dpt.name as department_name,
                prj.name as project_name,
                {$lines}.quantity as quantity,
                {$lines}.unit_price as unit_price,
                {$lines}.line_total as line_total,
                {$lines}.description as description
            ")
            ->get()
            ->map(fn ($row) => [
                'date' => $this->toDateString($row->document_date),
                'document_type' => $source['type'],
                'document_id' => (int) $row->document_id,
                'document_number' => (string) $row->document_number,
                'direction' => $source['direction'],
                'contact_name' => $row->contact_name !== null ? (string) $row->contact_name : null,
                'description' => $row->description !== null ? (string) $row->description : null,
                'quantity' => $source['sign'] * (float) $row->quantity,
                'unit_price' => (float) $row->unit_price,
                'line_total' => (float) $row->line_total,
                'department_name' => $row->department_name !== null ? (string) $row->department_name : null,
                'project_name' => $row->project_name !== null ? (string) $row->project_name : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Pergerakan stok non-komersial: penyesuaian, opname, saldo awal, transfer.
     *
     * Harganya memakai HPP (`unit_cost`), bukan harga jual/beli -- pergerakan
     * ini memang tidak punya harga transaksi. Karena itu ia juga tidak ikut
     * dihitung ke rata-rata beli/jual di `totals()`; kalau ikut, rata-rata harga
     * jual akan tertarik ke HPP dan tidak lagi berarti apa pun.
     *
     * @param  array<string,mixed>  $filters
     * @return list<array<string,mixed>>
     */
    private function fetchInventoryMovements(array $filters): array
    {
        $query = StockMovementLine::query()
            ->join('stock_movements as hdr', 'hdr.id', '=', 'stock_movement_lines.stock_movement_id')
            ->leftJoin('departments as dpt', 'dpt.id', '=', 'stock_movement_lines.department_id')
            ->leftJoin('projects as prj', 'prj.id', '=', 'stock_movement_lines.project_id')
            ->where('hdr.status', 'posted')
            ->where('stock_movement_lines.product_id', (int) $filters['product_id'])
            ->where(function ($q) {
                $q->whereNull('hdr.source_type')
                    ->orWhereNotIn('hdr.source_type', self::MOVEMENT_SOURCES_ALREADY_COVERED);
            });

        $this->applyDateRange($query, 'hdr.movement_date', $filters);

        if (! empty($filters['department_id'])) {
            $query->where('stock_movement_lines.department_id', (int) $filters['department_id']);
        }
        if (! empty($filters['project_id'])) {
            $query->where('stock_movement_lines.project_id', (int) $filters['project_id']);
        }

        return $query
            ->selectRaw('
                hdr.id as document_id,
                hdr.movement_date as document_date,
                hdr.movement_number as document_number,
                hdr.source_type as source_type,
                hdr.source_id as source_id,
                hdr.source_number as source_number,
                hdr.movement_type as movement_type,
                dpt.name as department_name,
                prj.name as project_name,
                stock_movement_lines.direction as direction,
                stock_movement_lines.quantity as quantity,
                stock_movement_lines.unit_cost as unit_cost,
                stock_movement_lines.total_cost as total_cost
            ')
            ->get()
            ->map(fn ($row) => [
                'date' => $this->toDateString($row->document_date),
                // Jenis dan id yang dikirim adalah milik dokumen SUMBER
                // (`stock_adjustment` id 2), bukan pergerakan stoknya (id 27) --
                // pergerakan tidak punya halaman sendiri, jadi mengirim idnya
                // membuat barisnya tidak bisa dibuka. `source_id` sudah ada di
                // tabelnya; tinggal dipakai.
                //
                // Fallback ke pergerakan hanya untuk baris tanpa sumber sama
                // sekali (mis. saldo awal hasil impor).
                'document_type' => $this->movementDocumentType($row->source_type),
                'document_id' => (int) ($row->source_id ?: $row->document_id),
                // Nomor dokumen sumber (mis. SA-2026-000001) lebih dikenali user
                // daripada nomor pergerakan internal.
                'document_number' => (string) ($row->source_number ?: $row->document_number),
                'direction' => (string) $row->direction === 'in' ? 'in' : 'out',
                'contact_name' => null,
                'description' => (string) $row->movement_type,
                'quantity' => ((string) $row->direction === 'in' ? 1 : -1) * (float) $row->quantity,
                'unit_price' => (float) $row->unit_cost,
                'line_total' => (float) $row->total_cost,
                'department_name' => $row->department_name !== null ? (string) $row->department_name : null,
                'project_name' => $row->project_name !== null ? (string) $row->project_name : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Petakan `source_type` pergerakan stok ke jenis dokumen yang punya halaman
     * sendiri, supaya frontend bisa menautkannya.
     *
     * Data lama memakai `adjustment`/`opname`, data baru memakai
     * `stock_adjustment`/`stock_opname` -- keduanya diterima. Sumber yang tidak
     * dikenali (mis. `opening`, atau jenis baru) jatuh ke `stock_movement`, dan
     * frontend merendernya sebagai teks biasa: lebih baik tidak bisa diklik
     * daripada membuka dokumen yang salah.
     */
    private function movementDocumentType(mixed $sourceType): string
    {
        return match ((string) $sourceType) {
            'stock_adjustment', 'adjustment' => 'stock_adjustment',
            'stock_opname', 'opname' => 'stock_opname',
            'inventory_transfer', 'transfer' => 'stock_transfer',
            // Saldo awal tidak punya dokumen sumber -- ia titik mulai
            // pembukuan, bukan hasil transaksi. Tetap diberi jenis sendiri
            // supaya labelnya jelas, walau tidak bisa ditautkan.
            'opening', 'opening_stock', 'opening_balance' => 'opening_balance',
            default => 'stock_movement',
        };
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function applyDateRange(Builder $query, string $column, array $filters): void
    {
        if (! empty($filters['start_date'])) {
            $query->where($column, '>=', (string) $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $query->where($column, '<=', (string) $filters['end_date']);
        }
    }

    private function toDateString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }

    /**
     * Sisi beli dan jual dijumlahkan terpisah; nilainya selalu positif supaya
     * bisa dibaca sebagai "total dibeli"/"total dijual", sementara `quantity`
     * per baris tetap bertanda.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,float>
     */
    private function totals(array $rows): array
    {
        $purchasedQty = 0.0;
        $purchasedValue = 0.0;
        $soldQty = 0.0;
        $soldValue = 0.0;
        $adjustedQty = 0.0;

        foreach ($rows as $row) {
            $qty = abs((float) $row['quantity']);
            $value = abs((float) $row['line_total']);

            // Pergerakan non-komersial dihitung terpisah dan TIDAK masuk
            // rata-rata beli/jual -- harganya HPP, bukan harga transaksi.
            if (in_array($row['document_type'], self::INVENTORY_DOCUMENT_TYPES, true)) {
                $adjustedQty += (float) $row['quantity'];

                continue;
            }

            $isPurchase = in_array($row['document_type'], ['vendor_bill', 'purchase_return'], true);
            // Retur membalik tandanya: retur pembelian mengurangi total dibeli,
            // retur penjualan mengurangi total dijual. Tanpa ini "total dibeli"
            // ikut naik saat barang justru dikembalikan ke supplier.
            $isReturn = in_array($row['document_type'], ['sales_return', 'purchase_return'], true);
            $factor = $isReturn ? -1 : 1;

            if ($isPurchase) {
                $purchasedQty += $factor * $qty;
                $purchasedValue += $factor * $value;
            } else {
                $soldQty += $factor * $qty;
                $soldValue += $factor * $value;
            }
        }

        return [
            'purchased_qty' => round($purchasedQty, 4),
            'purchased_value' => round($purchasedValue, 2),
            'sold_qty' => round($soldQty, 4),
            'sold_value' => round($soldValue, 2),
            // Bertanda: negatif berarti stok berkurang lewat penyesuaian.
            'adjusted_qty' => round($adjustedQty, 4),
            // Rata-rata TERTIMBANG: nilai dibagi kuantitas, bukan AVG(unit_price).
            // Rata-rata polos memberi bobot sama pada transaksi 1 unit dan 1.000
            // unit, dan hasilnya tidak akan pernah cocok dengan nilai/qty.
            'avg_buy_price' => $this->safeDivide($purchasedValue, $purchasedQty),
            'avg_sell_price' => $this->safeDivide($soldValue, $soldQty),
        ];
    }

    private function safeDivide(float $numerator, float $denominator): float
    {
        if (abs($denominator) < 1e-9) {
            return 0.0;
        }

        return round($numerator / $denominator, 2);
    }
}
