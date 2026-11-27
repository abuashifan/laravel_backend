<?php

namespace App\Modules\Gudang;

use App\Shared\Models\Company;
use App\Shared\Tenant\TenantContext;
use App\Shared\Warehouse\OutletReceivingGateway;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Jembatan Finlite Gudang -> Finlite Kasir (Fase 4a).
 *
 * Satu surat jalan yang sudah tercatat dikirim sebagai satu kiriman ke kotak masuk
 * Kasir. Arus satu arah dan idempoten: kunci unik pada dokumen dan kunci kiriman
 * yang dibaca Kasir menjaga agar kiriman yang sama tidak pernah tercatat dua kali.
 * Gudang memiliki stok gudang, Kasir memiliki stok toko; selisih saat terima
 * dilaporkan balik sebagai pengecualian dan tidak mengubah stok siapa pun diam-diam.
 *
 * Kegagalan mengirim tidak pernah menggagalkan surat jalan: kiriman ditandai
 * gagal/ditahan dan bisa dikirim ulang.
 */
class PosBridge
{
    /** Kiriman yang belum sampai ke Kasir. */
    public const OPEN = ['pending', 'held', 'failed'];

    /** Kiriman yang sudah ada di Kasir; surat jalannya tidak bisa dikoreksi lagi. */
    public const SENT = ['delivered', 'received', 'discrepancy'];

    public const STATUSES = ['pending', 'held', 'failed', 'delivered', 'received', 'discrepancy'];

    public function __construct(
        private readonly OutletReceivingGateway $outlet,
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

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['bridge' => $message]);
        }
    }

    // ---- Keadaan jembatan ----

    /** @return array<string,mixed> */
    public function state(): array
    {
        $config = $this->db()->table('gudang_pos_bridge_config')->first();
        $eligible = $this->outlet->available($this->company());
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->scoped()->groupBy('p.status')->selectRaw('p.status, count(*) as total')->pluck('total', 'status') as $status => $total) {
            $counts[$status] = (int) $total;
        }

        return [
            'eligible' => $eligible,
            'reason' => $eligible ? null : 'Perusahaan ini belum memakai Finlite Kasir. Aktifkan Finlite Kasir dulu untuk mengirim barang ke toko.',
            'enabled' => (bool) ($config->enabled ?? false),
            'connected_at' => $config->connected_at ?? null,
            'disconnected_at' => $config->disconnected_at ?? null,
            'counts' => $counts,
            'unresolved' => $counts['discrepancy'] > 0
                ? (int) $this->scoped()->where('p.status', 'discrepancy')->whereNull('p.acknowledged_at')->count()
                : 0,
        ];
    }

    public function enabled(): bool
    {
        return (bool) $this->db()->table('gudang_pos_bridge_config')->value('enabled');
    }

    /** Sambungkan atau putuskan jembatan. Memutus hanya menghentikan kiriman baru. */
    public function setEnabled(int $actor, bool $enabled): array
    {
        if ($enabled) {
            $this->check($this->outlet->available($this->company()), 'Perusahaan ini belum memakai Finlite Kasir.');
        }
        $db = $this->db();
        $config = $db->table('gudang_pos_bridge_config')->first();
        if (! $config) {
            $db->table('gudang_pos_bridge_config')->insert(['enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
            $config = $db->table('gudang_pos_bridge_config')->first();
        }
        if ((bool) $config->enabled !== $enabled) {
            $db->table('gudang_pos_bridge_config')->where('id', $config->id)->update($enabled
                ? ['enabled' => true, 'connected_by' => $actor, 'connected_at' => now(), 'disconnected_at' => null, 'updated_at' => now()]
                : ['enabled' => false, 'disconnected_at' => now(), 'updated_at' => now()]);
        }

        return $this->state();
    }

    // ---- Daftar kiriman ----

    /** Kiriman pada surat jalan di gudang yang boleh diakses pengguna. */
    private function scoped(): Builder
    {
        return $this->db()->table('gudang_pos_dispatches as p')
            ->join('gudang_stock_documents as d', 'd.id', '=', 'p.document_id')
            ->whereIn('d.location_id', $this->access->locations());
    }

    /**
     * Kiriman terbaru dengan hasil terima dari Kasir. Memperbarui status kiriman
     * yang masih menunggu konfirmasi toko lebih dulu.
     *
     * @return array{data: list<object>, total:int}
     */
    public function list(?string $status = null): array
    {
        $this->refresh();
        $rows = $this->scoped()
            ->when($status, fn ($query) => $query->where('p.status', $status))
            ->leftJoin('gudang_locations as l', 'l.id', '=', 'd.location_id')
            ->leftJoin('gudang_warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->orderByDesc('p.id')->limit(100)
            ->get(['p.id', 'p.document_id', 'p.status', 'p.attempts', 'p.error', 'p.summary', 'p.delivered_at', 'p.received_at', 'p.received_by', 'p.receive_note',
                'p.acknowledged_at', 'p.acknowledge_note', 'p.created_at', 'd.serial', 'd.source', 'd.document_date', 'w.name as warehouse']);

        return ['data' => $rows->map(function ($row) {
            $row->number = WarehouseDocuments::PREFIX['issue'].'-'.$row->serial;
            $row->summary = $row->summary ? json_decode($row->summary, true) : [];

            return $row;
        })->all(), 'total' => $rows->count()];
    }

    /** Status kiriman per dokumen untuk daftar dokumen: [document_id => status]. */
    public function statusByDocument(array $documentIds): array
    {
        return $documentIds === [] ? [] : $this->db()->table('gudang_pos_dispatches')->whereIn('document_id', $documentIds)->pluck('status', 'document_id')->all();
    }

    // ---- Pengiriman ----

    /** Kirim satu surat jalan ke Kasir. Surat jalan yang sudah punya kiriman dikembalikan apa adanya. */
    public function send(int $documentId, int $actor): object
    {
        $db = $this->db();
        $this->check($this->enabled(), 'Jembatan ke Kasir belum tersambung.');
        $existing = $db->table('gudang_pos_dispatches')->where('document_id', $documentId)->first();
        if ($existing) {
            return $existing;
        }
        $doc = $db->table('gudang_stock_documents')->where('id', $documentId)->firstOrFail();
        $this->access->requireLocations([$doc->location_id]);
        $this->check($doc->type === 'issue' && $doc->status === 'posted', 'Hanya surat jalan yang sudah dicatat yang bisa dikirim ke Kasir.');
        $this->check(! $db->table('gudang_stock_documents')->where('reverses_id', $documentId)->exists(), 'Surat jalan ini sudah dikoreksi dan tidak bisa dikirim.');

        $db->table('gudang_pos_dispatches')->insertOrIgnore([
            'document_id' => $documentId, 'receipt_key' => (string) Str::uuid(), 'status' => 'pending', 'sent_by' => $actor,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->deliver((int) $db->table('gudang_pos_dispatches')->where('document_id', $documentId)->value('id'));
    }

    /**
     * Antar satu kiriman ke Kasir. Barang yang belum ada di Kasir menahan kiriman
     * (bukan gagal) supaya petugas bisa mengimpornya lalu mengirim ulang.
     */
    public function deliver(int $id, bool $importMissing = false): object
    {
        $db = $this->db();
        $dispatch = $db->table('gudang_pos_dispatches')->where('id', $id)->firstOrFail();
        if (in_array($dispatch->status, self::SENT, true)) {
            return $dispatch;
        }
        $db->table('gudang_pos_dispatches')->where('id', $id)->update(['attempts' => $dispatch->attempts + 1, 'updated_at' => now()]);

        try {
            $doc = $db->table('gudang_stock_documents')->where('id', $dispatch->document_id)->firstOrFail();
            $warehouse = $db->table('gudang_locations as l')->join('gudang_warehouses as w', 'w.id', '=', 'l.warehouse_id')->where('l.id', $doc->location_id)->value('w.name');
            $items = $db->table('gudang_doc_lines as x')->join('gudang_items as i', 'i.id', '=', 'x.item_id')->where('x.document_id', $doc->id)
                ->orderBy('x.id')->get(['i.id', 'i.sku', 'i.name', 'i.unit', 'x.quantity_milli']);
            $barcodes = $db->table('gudang_barcodes')->whereIn('item_id', $items->pluck('id'))->get(['item_id', 'code'])->groupBy('item_id');
            $company = $this->company();
            $request = $items->map(fn ($item) => ['sku' => $item->sku, 'barcodes' => $barcodes->get($item->id, collect())->pluck('code')->all()])->all();

            $matched = $this->outlet->match($company, $request);
            $missing = $items->filter(fn ($item) => ! isset($matched[$item->sku]));
            if ($missing->isNotEmpty() && $importMissing) {
                $this->outlet->importProducts($company, $missing->map(fn ($item) => [
                    'sku' => $item->sku, 'name' => $item->name, 'unit' => $item->unit,
                    'barcode' => $barcodes->get($item->id, collect())->pluck('code')->first(),
                ])->values()->all());
                $matched = $this->outlet->match($company, $request);
                $missing = $items->filter(fn ($item) => ! isset($matched[$item->sku]));
            }
            $summary = $items->map(fn ($item) => [
                'sku' => $item->sku, 'name' => $item->name, 'unit' => $item->unit,
                'expected' => $this->quantity((int) $item->quantity_milli), 'actual' => null,
            ])->all();
            $db->table('gudang_pos_dispatches')->where('id', $id)->update(['summary' => json_encode($summary, JSON_THROW_ON_ERROR)]);

            if ($missing->isNotEmpty()) {
                return $this->mark($id, 'held', 'Barang belum ada di Kasir: '.$missing->pluck('sku')->implode(', ').'. Impor barang ke Kasir lalu kirim ulang.');
            }
            $delivered = $this->outlet->deliver($company, [
                'key' => $dispatch->receipt_key,
                'number' => WarehouseDocuments::PREFIX['issue'].'-'.$doc->serial,
                'from' => (string) $warehouse,
                'shipped_at' => (string) $doc->posted_at,
                'lines' => $items->map(fn ($item) => [
                    'product_id' => $matched[$item->sku]['product_id'], 'sku' => $item->sku, 'name' => $item->name,
                    'unit' => $item->unit, 'quantity' => $this->quantity((int) $item->quantity_milli),
                ])->values()->all(),
            ]);
            if (! $delivered) {
                return $this->mark($id, 'failed', 'Perusahaan ini belum memakai Finlite Kasir.');
            }

            return $this->mark($id, 'delivered', null, ['delivered_at' => now()]);
        } catch (Throwable $e) {
            return $this->mark($id, 'failed', mb_substr($e->getMessage(), 0, 500));
        }
    }

    /** Kirim ulang kiriman yang gagal atau ditahan; `importMissing` membuat produk Kasir yang belum ada. */
    public function retry(int $id, bool $importMissing = false): object
    {
        $this->check($this->enabled(), 'Jembatan ke Kasir sedang diputus. Sambungkan dulu untuk mengirim.');
        $dispatch = $this->scoped()->where('p.id', $id)->first(['p.id', 'p.status']);
        abort_unless($dispatch, 404);
        $this->check(in_array($dispatch->status, self::OPEN, true), 'Kiriman ini sudah sampai ke Kasir.');

        return $this->deliver($id, $importMissing);
    }

    /**
     * Impor seluruh barang Gudang ke Kasir sekali jalan (produk yang sudah ada dilewati).
     *
     * @return array{created:int, skipped:int}
     */
    public function importProducts(): array
    {
        $this->check($this->enabled(), 'Jembatan ke Kasir belum tersambung.');
        $db = $this->db();
        $barcodes = $db->table('gudang_barcodes')->get(['item_id', 'code'])->groupBy('item_id');
        $items = $db->table('gudang_items')->orderBy('id')->get(['id', 'sku', 'name', 'unit'])->map(fn ($item) => [
            'sku' => $item->sku, 'name' => $item->name, 'unit' => $item->unit,
            'barcode' => $barcodes->get($item->id, collect())->pluck('code')->first(),
        ])->all();

        return $this->outlet->importProducts($this->company(), $items);
    }

    // ---- Hasil terima dari Kasir ----

    /**
     * Baca hasil terima untuk kiriman yang belum dikonfirmasi toko. Selisih
     * menjadi pengecualian untuk ditindaklanjuti; stok Gudang tidak berubah.
     */
    public function refresh(): int
    {
        $db = $this->db();
        $waiting = $db->table('gudang_pos_dispatches')->where('status', 'delivered')->limit(100)->get(['id', 'receipt_key', 'summary']);
        if ($waiting->isEmpty()) {
            return 0;
        }
        try {
            $reported = $this->outlet->statuses($this->company(), $waiting->pluck('receipt_key')->all());
        } catch (Throwable) {
            return 0;
        }
        $changed = 0;
        foreach ($waiting as $row) {
            $report = $reported[$row->receipt_key] ?? null;
            if (! $report || $report['status'] === 'pending') {
                continue;
            }
            $actual = collect($report['lines'])->pluck('actual', 'sku');
            $summary = collect(json_decode((string) $row->summary, true) ?: [])->map(function (array $line) use ($actual) {
                $line['actual'] = $actual->get($line['sku']);

                return $line;
            })->all();
            $db->table('gudang_pos_dispatches')->where('id', $row->id)->update([
                'status' => $report['status'], 'summary' => json_encode($summary, JSON_THROW_ON_ERROR), 'received_at' => $report['received_at'],
                'received_by' => $report['received_by'], 'receive_note' => $report['note'], 'updated_at' => now(),
            ]);
            $changed++;
        }

        return $changed;
    }

    /** Tandai selisih terima sudah ditindaklanjuti. Stok tidak berubah; tindakan koreksinya dilakukan lewat dokumen biasa. */
    public function acknowledge(int $id, int $actor, string $note): object
    {
        $dispatch = $this->scoped()->where('p.id', $id)->first(['p.id', 'p.status', 'p.acknowledged_at']);
        abort_unless($dispatch, 404);
        $this->check($dispatch->status === 'discrepancy', 'Hanya kiriman dengan selisih yang perlu ditindaklanjuti.');
        $note = trim($note);
        $this->check($note !== '', 'Tulis tindak lanjut selisih.');
        if ($dispatch->acknowledged_at === null) {
            $this->db()->table('gudang_pos_dispatches')->where('id', $id)->update([
                'acknowledged_at' => now(), 'acknowledged_by' => $actor, 'acknowledge_note' => $note, 'updated_at' => now(),
            ]);
        }

        return $this->db()->table('gudang_pos_dispatches')->where('id', $id)->first();
    }

    /**
     * Dipanggil sebelum surat jalan dikoreksi. Kiriman yang belum sampai ke Kasir
     * dibatalkan bersama surat jalannya; yang sudah sampai menolak koreksi karena
     * stok tokonya sudah berpindah ke Kasir.
     */
    public function guardReversal(int $documentId): void
    {
        $dispatch = $this->db()->table('gudang_pos_dispatches')->where('document_id', $documentId)->first();
        if (! $dispatch) {
            return;
        }
        $this->check(! in_array($dispatch->status, self::SENT, true), 'Surat jalan ini sudah dikirim ke Kasir dan tidak bisa dikoreksi. Barang yang kembali dicatat sebagai retur pelanggan.');
        $this->db()->table('gudang_pos_dispatches')->where('id', $dispatch->id)->delete();
    }

    /** @param  array<string,mixed>  $extra */
    private function mark(int $id, string $status, ?string $error, array $extra = []): object
    {
        $this->db()->table('gudang_pos_dispatches')->where('id', $id)->update(['status' => $status, 'error' => $error, 'updated_at' => now()] + $extra);

        return $this->db()->table('gudang_pos_dispatches')->where('id', $id)->first();
    }

    private function quantity(int $milli): string
    {
        return rtrim(rtrim(number_format($milli / 1000, 3, '.', ''), '0'), '.');
    }
}
