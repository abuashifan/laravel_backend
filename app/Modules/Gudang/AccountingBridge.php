<?php

namespace App\Modules\Gudang;

use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use App\Shared\Tenant\TenantContext;
use App\Shared\Warehouse\InventoryAccountingGateway;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Jembatan Finlite Gudang -> Finlite Accounting (Fase 4b).
 *
 * Gudang memiliki kuantitas fisik, Accounting memiliki nilai (dokumen 10 bagian 9). Penerimaan
 * dikirim sebagai Goods Receipt Accounting, sehingga tagihan vendor dibuat "Dari Goods Receipt"
 * dan stok tidak tercatat dua kali. Penyesuaian dan selisih opname dikirim sebagai penyesuaian
 * stok yang dinilai Accounting dengan rata-rata. Surat jalan, transfer, dan retur tidak dikirim:
 * penjualan dan retur dicatat di Accounting lewat dokumennya sendiri, transfer di dalam satu
 * perusahaan tidak dijurnal.
 *
 * Arus satu arah dan idempoten: satu dokumen Gudang paling banyak satu kiriman, dan Accounting
 * memeriksa sumber dokumen sebelum mencatat. Kegagalan mengirim tidak pernah menggagalkan
 * transaksi gudang; kiriman ditandai ditahan/gagal dan bisa dikirim ulang.
 */
class AccountingBridge
{
    public const STATUSES = ['pending', 'held', 'failed', 'sent'];

    /** Batas kiriman sekali jalan supaya request tidak kelamaan. */
    private const BATCH = 50;

    public function __construct(
        private readonly InventoryAccountingGateway $accounting,
        private readonly TenantContext $tenant,
        private readonly WarehouseAccess $access,
    ) {}

    private function db(): Connection
    {
        return DB::connection('gudang');
    }

    private function company(): Company
    {
        return $this->tenant->company() ?? throw new \RuntimeException('Perusahaan belum dipilih.');
    }

    private function check(bool $ok, string $message, string $field = 'bridge'): void
    {
        if (! $ok) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function config(): ?object
    {
        return $this->db()->table('gudang_accounting_bridge_config')->first();
    }

    // ---- Pengaturan ----

    /** @return array<string,mixed> */
    public function state(): array
    {
        $config = $this->config();
        $eligible = $this->accounting->available($this->company());
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->scoped()->groupBy('a.status')->selectRaw('a.status, count(*) as total')->pluck('total', 'status') as $status => $total) {
            $counts[$status] = (int) $total;
        }

        return [
            'eligible' => $eligible,
            'reason' => $eligible ? null : 'Perusahaan ini belum memakai Finlite Accounting. Aktifkan Finlite Accounting dulu untuk mencatat persediaan ke pembukuan.',
            'enabled' => (bool) ($config->enabled ?? false),
            'warehouse_id' => isset($config->warehouse_id) ? (int) $config->warehouse_id : null,
            'vendor_id' => isset($config->vendor_id) ? (int) $config->vendor_id : null,
            'start_date' => $config->start_date ?? null,
            'connected_at' => $config->connected_at ?? null,
            'disconnected_at' => $config->disconnected_at ?? null,
            'counts' => $counts,
        ];
    }

    /** Gudang dan pemasok di Accounting untuk dipilih. */
    public function options(): array
    {
        return $this->accounting->options($this->company())
            ?? throw ValidationException::withMessages(['bridge' => 'Perusahaan ini belum memakai Finlite Accounting.']);
    }

    /**
     * Simpan pengaturan. Menyalakan jembatan menuntut gudang dan pemasok bawaan di Accounting;
     * memutus hanya menghentikan kiriman baru.
     *
     * @param  array{enabled:bool, warehouse_id?:?int, vendor_id?:?int, start_date?:?string}  $input
     */
    public function save(int $actor, array $input): array
    {
        $config = $this->config();
        $warehouse = array_key_exists('warehouse_id', $input) ? $input['warehouse_id'] : ($config->warehouse_id ?? null);
        $vendor = array_key_exists('vendor_id', $input) ? $input['vendor_id'] : ($config->vendor_id ?? null);
        $start = array_key_exists('start_date', $input) ? $input['start_date'] : ($config->start_date ?? null);
        $enabled = (bool) $input['enabled'];
        if ($enabled) {
            $options = $this->options();
            $this->check($warehouse !== null && in_array((int) $warehouse, array_column($options['warehouses'], 'id'), true), 'Pilih gudang Accounting tujuan.', 'warehouse_id');
            $this->check($vendor !== null && in_array((int) $vendor, array_column($options['vendors'], 'id'), true), 'Pilih pemasok bawaan untuk penerimaan yang asalnya tidak dikenal di Accounting.', 'vendor_id');
            $start ??= now('Asia/Jakarta')->toDateString();
        }
        $values = ['enabled' => $enabled, 'warehouse_id' => $warehouse, 'vendor_id' => $vendor, 'start_date' => $start, 'updated_at' => now()];
        $wasEnabled = (bool) ($config->enabled ?? false);
        if ($enabled && ! $wasEnabled) {
            $values += ['connected_by' => $actor, 'connected_at' => now(), 'disconnected_at' => null];
        } elseif (! $enabled && $wasEnabled) {
            $values += ['disconnected_at' => now()];
        }
        if ($config) {
            $this->db()->table('gudang_accounting_bridge_config')->where('id', $config->id)->update($values);
        } else {
            $this->db()->table('gudang_accounting_bridge_config')->insert($values + ['created_at' => now()]);
        }
        if ($enabled) {
            $this->syncQuietly();
        }

        return $this->state();
    }

    // ---- Antrean ----

    /** Kiriman pada dokumen di gudang yang boleh diakses pengguna. */
    private function scoped(): Builder
    {
        $db = $this->db();

        return $db->table('gudang_accounting_dispatches as a')
            ->join('gudang_stock_documents as d', 'd.id', '=', 'a.document_id')
            ->where(function ($q) use ($db) {
                $q->whereIn('d.location_id', $this->access->locations())
                    ->orWhere(fn ($multi) => $multi->whereNull('d.location_id')
                        ->whereIn('d.id', $db->table('gudang_stock_movements')->select('document_id')->whereIn('location_id', $this->access->locations())));
            });
    }

    /** Dokumen yang menambah atau mengurangi nilai persediaan dan belum punya kiriman. */
    private function eligible(string $since): Builder
    {
        $db = $this->db();

        return $db->table('gudang_stock_documents as d')
            ->where(fn ($q) => $q->where(fn ($r) => $r->where('d.type', 'receipt')->whereIn('d.status', ['posted', 'received']))
                ->orWhere(fn ($a) => $a->where('d.type', 'adjustment')->where('d.status', 'approved')))
            ->where('d.document_date', '>=', $since)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('gudang_stock_documents as k')->whereColumn('k.reverses_id', 'd.id'))
            ->whereNotIn('d.id', $db->table('gudang_accounting_dispatches')->select('document_id'));
    }

    /**
     * Antrekan dokumen baru lalu kirim yang belum terkirim. Aman dipanggil berulang.
     *
     * @return array{queued:int, sent:int, held:int, failed:int, remaining:int}
     */
    public function sync(): array
    {
        $result = ['queued' => 0, 'sent' => 0, 'held' => 0, 'failed' => 0, 'remaining' => 0];
        $config = $this->config();
        if (! ($config->enabled ?? false) || ! $this->accounting->available($this->company())) {
            return $result;
        }
        $db = $this->db();
        foreach ($this->eligible((string) $config->start_date)->orderBy('d.id')->limit(self::BATCH * 4)->get(['d.id', 'd.type']) as $doc) {
            $result['queued'] += (int) $db->table('gudang_accounting_dispatches')->insertOrIgnore([
                'document_id' => $doc->id, 'kind' => $doc->type === 'receipt' ? 'receipt' : 'adjustment', 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $open = $db->table('gudang_accounting_dispatches')->whereIn('status', ['pending', 'held', 'failed'])->orderBy('id');
        foreach ((clone $open)->limit(self::BATCH)->pluck('id') as $id) {
            $status = $this->deliver((int) $id)->status;
            $result[$status] = ($result[$status] ?? 0) + 1;
        }
        $result['remaining'] = (clone $open)->count();

        return $result;
    }

    /** Dipanggil setelah transaksi gudang tersimpan. Tidak pernah melempar. */
    public function syncQuietly(): void
    {
        try {
            $this->sync();
        } catch (Throwable $e) {
            Log::warning('Jembatan Gudang ke Accounting: '.$e->getMessage());
        }
    }

    // ---- Pengiriman ----

    public function deliver(int $id): object
    {
        $db = $this->db();
        $dispatch = $db->table('gudang_accounting_dispatches')->where('id', $id)->firstOrFail();
        if ($dispatch->status === 'sent') {
            return $dispatch;
        }
        $db->table('gudang_accounting_dispatches')->where('id', $id)->update(['attempts' => $dispatch->attempts + 1, 'updated_at' => now()]);

        try {
            $config = $this->config();
            $doc = $db->table('gudang_stock_documents')->where('id', $dispatch->document_id)->firstOrFail();
            $number = WarehouseDocuments::PREFIX[$doc->type].'-'.$doc->serial;
            $lines = $this->lines((int) $doc->id);
            $company = $this->company();
            $matched = $this->accounting->match($company, $lines->pluck('sku')->all());
            $summary = $lines->map(fn ($line) => [
                'item_id' => $line->item_id, 'sku' => $line->sku, 'name' => $line->name, 'unit' => $line->unit,
                'quantity' => $this->quantity((int) $line->quantity_milli), 'unit_cost' => $line->unit_cost,
            ])->values()->all();
            $db->table('gudang_accounting_dispatches')->where('id', $id)->update(['summary' => json_encode($summary, JSON_THROW_ON_ERROR)]);

            $missing = $lines->reject(fn ($line) => isset($matched[$line->sku]));
            if ($missing->isNotEmpty()) {
                return $this->mark($id, 'held', 'Produk belum ada di Accounting: '.$missing->pluck('sku')->implode(', ')
                    .'. Buat produk persediaan dengan kode produk yang sama dengan SKU, lalu kirim ulang.');
            }
            $date = (string) ($doc->document_date ?? now('Asia/Jakarta')->toDateString());
            $result = $dispatch->kind === 'receipt'
                ? $this->accounting->postReceipt($company, [
                    'source_id' => (int) $doc->id, 'number' => $number, 'date' => $date, 'supplier' => (string) $doc->source,
                    'vendor_id' => (int) $config->vendor_id, 'warehouse_id' => (int) $config->warehouse_id,
                    'lines' => $lines->map(fn ($line) => [
                        'product_id' => $matched[$line->sku]['product_id'], 'description' => $line->sku.' · '.$line->name,
                        'quantity' => $line->quantity_milli / 1000, 'unit_cost' => $line->unit_cost,
                    ])->values()->all(),
                ])
                : $this->accounting->postAdjustment($company, [
                    'source_id' => (int) $doc->id, 'number' => $number, 'date' => $date, 'warehouse_id' => (int) $config->warehouse_id,
                    'description' => 'Penyesuaian Finlite Gudang '.$number.' · '.$doc->source,
                    'lines' => $lines->map(fn ($line) => ['product_id' => $matched[$line->sku]['product_id'], 'quantity' => $line->quantity_milli / 1000])->values()->all(),
                ]);

            return $this->mark($id, 'sent', null, ['reference' => mb_substr($result['reference'], 0, 255), 'sent_at' => now()]);
        } catch (ApiException $e) {
            [$status, $message] = $this->explain($e);

            return $this->mark($id, $status, $message);
        } catch (ValidationException $e) {
            return $this->mark($id, 'failed', mb_substr(collect($e->errors())->flatten()->implode(' '), 0, 500));
        } catch (Throwable $e) {
            return $this->mark($id, 'failed', mb_substr($e->getMessage(), 0, 500));
        }
    }

    /**
     * Jumlah bersih per barang dan harga beli rata-rata (bila semua baris punya harga).
     *
     * @return Collection<int, object>
     */
    private function lines(int $document)
    {
        return $this->db()->table('gudang_stock_movements as m')->join('gudang_items as i', 'i.id', '=', 'm.item_id')
            ->where('m.document_id', $document)
            ->groupBy('m.item_id', 'i.sku', 'i.name', 'i.unit')
            ->havingRaw('sum(m.quantity_milli) <> 0')
            ->orderBy('i.sku')
            ->get(['m.item_id', 'i.sku', 'i.name', 'i.unit', DB::raw('sum(m.quantity_milli) as quantity_milli'),
                DB::raw('case when count(m.unit_cost) = count(*) then sum(m.unit_cost * m.quantity_milli) / sum(m.quantity_milli) end as unit_cost')])
            ->map(function ($line) {
                $line->quantity_milli = (int) $line->quantity_milli;
                $line->unit_cost = $line->unit_cost === null ? null : round((float) $line->unit_cost, 2);

                return $line;
            });
    }

    /** @return array{0:string, 1:string} status dan pesan berbahasa Indonesia */
    private function explain(ApiException $e): array
    {
        $code = $e->codeName;

        return match (true) {
            $code === 'ACCOUNT_MAPPING_MISSING' => ['held', $e->getMessage()],
            $code === 'INSUFFICIENT_STOCK' => ['held', 'Stok di gudang Accounting tidak cukup untuk pengurangan ini. Pastikan penerimaan sebelumnya sudah terkirim, lalu kirim ulang.'],
            str_contains($code, 'PERIOD') || str_contains($code, 'FISCAL') || str_contains($code, 'LOCK') || str_contains($code, 'DATE') => ['held', 'Ditahan: tanggal dokumen ditolak Accounting ('.$e->getMessage().'). Buka periodenya lalu kirim ulang.'],
            $code === 'VENDOR_NOT_VALID' || $code === 'VENDOR_NOT_FOUND' => ['held', 'Pemasok bawaan tidak aktif di Accounting. Pilih pemasok lain di pengaturan jembatan.'],
            default => ['failed', mb_substr($e->getMessage(), 0, 500)],
        };
    }

    /** Kirim ulang satu kiriman yang belum terkirim. */
    public function retry(int $id): object
    {
        $this->check((bool) ($this->config()->enabled ?? false), 'Jembatan ke Accounting sedang diputus. Sambungkan dulu untuk mengirim.');
        $dispatch = $this->scoped()->where('a.id', $id)->first(['a.id', 'a.status']);
        abort_unless($dispatch, 404);
        $this->check($dispatch->status !== 'sent', 'Kiriman ini sudah tercatat di Accounting.');

        return $this->deliver($id);
    }

    /**
     * Isi harga beli penerimaan yang belum terkirim (per satuan dasar), lalu kirim ulang.
     *
     * @param  array<int, float>  $costs  item_id => harga
     */
    public function setCosts(int $id, array $costs): object
    {
        $dispatch = $this->scoped()->where('a.id', $id)->first(['a.id', 'a.status', 'a.kind', 'a.document_id']);
        abort_unless($dispatch, 404);
        $this->check($dispatch->kind === 'receipt', 'Harga beli hanya untuk penerimaan.');
        $this->check($dispatch->status !== 'sent', 'Kiriman ini sudah tercatat di Accounting. Ubah nilainya di Accounting.');
        foreach ($costs as $item => $cost) {
            $this->db()->table('gudang_stock_movements')->where('document_id', $dispatch->document_id)->where('item_id', (int) $item)
                ->where('quantity_milli', '>', 0)->update(['unit_cost' => round((float) $cost, 2)]);
        }

        return $this->retry($id);
    }

    /** @return array{data: list<object>, total:int} */
    public function list(?string $status = null): array
    {
        $rows = $this->scoped()
            ->when($status, fn ($q) => $q->where('a.status', $status))
            ->orderByDesc('a.id')->limit(100)
            ->get(['a.id', 'a.document_id', 'a.kind', 'a.status', 'a.attempts', 'a.error', 'a.reference', 'a.summary', 'a.sent_at',
                'd.type', 'd.serial', 'd.source', 'd.document_date']);

        return ['data' => $rows->map(function ($row) {
            $row->number = WarehouseDocuments::PREFIX[$row->type].'-'.$row->serial;
            $row->summary = $row->summary ? json_decode($row->summary, true) : [];

            return $row;
        })->all(), 'total' => $rows->count()];
    }

    /** Status kiriman per dokumen untuk daftar dokumen: [document_id => status]. */
    public function statusByDocument(array $documentIds): array
    {
        return $documentIds === [] ? [] : $this->db()->table('gudang_accounting_dispatches')->whereIn('document_id', $documentIds)->pluck('status', 'document_id')->all();
    }

    /**
     * Dipanggil sebelum dokumen dikoreksi. Kiriman yang belum tercatat dibatalkan bersama dokumennya;
     * yang sudah tercatat menolak koreksi supaya stok Gudang dan Accounting tidak berpisah diam-diam.
     */
    public function guardReversal(int $documentId): void
    {
        $dispatch = $this->db()->table('gudang_accounting_dispatches')->where('document_id', $documentId)->first();
        if (! $dispatch) {
            return;
        }
        $this->check($dispatch->status !== 'sent', 'Dokumen ini sudah tercatat di Accounting ('.$dispatch->reference.') dan tidak bisa dikoreksi. Catat penyesuaian baru untuk memperbaiki jumlahnya.', 'document');
        $this->db()->table('gudang_accounting_dispatches')->where('id', $dispatch->id)->delete();
    }

    /** @param  array<string,mixed>  $extra */
    private function mark(int $id, string $status, ?string $error, array $extra = []): object
    {
        $this->db()->table('gudang_accounting_dispatches')->where('id', $id)->update(['status' => $status, 'error' => $error, 'updated_at' => now()] + $extra);

        return $this->db()->table('gudang_accounting_dispatches')->where('id', $id)->first();
    }

    private function quantity(int $milli): string
    {
        return rtrim(rtrim(number_format($milli / 1000, 3, '.', ''), '0'), '.');
    }
}
