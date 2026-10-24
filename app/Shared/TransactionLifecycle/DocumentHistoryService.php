<?php

namespace App\Shared\TransactionLifecycle;

use App\Shared\Models\TransactionRevision;
use App\Shared\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Riwayat edit dokumen transaksi: isi dokumen (header + baris) sebelum dan
 * sesudah tiap edit, siapa, dan kapan. Dicatat diam-diam -- user yang
 * mengedit tidak diminta apa pun -- dan hanya bisa dibaca oleh peran dengan
 * izin `audit.document_history.view`. Tidak ada restore: riwayat hanya untuk
 * dilihat.
 *
 * Disimpan di `transaction_revisions` (tabel yang juga dipakai jurnal manual).
 */
class DocumentHistoryService
{
    /**
     * Kolom yang berubah sendiri setiap posting ulang, bukan isi dokumen --
     * kalau ikut dibandingkan, tiap edit tampak mengubah kolom-kolom ini.
     */
    private const IGNORED_HEADER_FIELDS = [
        'created_at', 'updated_at', 'deleted_at', 'revision_no', 'posted_at', 'posted_by',
        'approved_at', 'approved_by', 'journal_entry_id', 'stock_movement_id', 'metadata',
        'updated_by', 'counted_at', 'counted_by', 'finalized_at', 'finalized_by',
    ];

    private const IGNORED_LINE_FIELDS = ['id', 'created_at', 'updated_at', 'deleted_at', 'counted_at', 'counted_by'];

    public function __construct(private readonly TransactionRevisionService $revisions) {}

    /**
     * Isi dokumen saat ini, dibaca ulang dari database (bukan dari model di
     * memori yang bisa membawa atribut tampilan).
     *
     * @return array{header: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    public function snapshot(Model $document): array
    {
        $fresh = $document->newQueryWithoutScopes()->find($document->getKey()) ?? $document;
        $lines = [];

        if (method_exists($fresh, 'lines') && ($relation = $fresh->lines()) instanceof HasMany) {
            $except = array_merge(self::IGNORED_LINE_FIELDS, [$relation->getForeignKeyName()]);
            $lines = $relation->get()
                ->map(fn (Model $line): array => Arr::except($line->getAttributes(), $except))
                ->values()
                ->all();
        }

        return [
            'header' => Arr::except($fresh->getAttributes(), self::IGNORED_HEADER_FIELDS),
            'lines' => $lines,
        ];
    }

    /**
     * Catat satu edit. Edit yang tidak mengubah isi apa pun tidak dicatat.
     *
     * @param  array{header: array<string, mixed>, lines: list<array<string, mixed>>}  $beforeSnapshot
     */
    public function recordEdit(array $beforeSnapshot, Model $after, array $metadata = []): ?TransactionRevision
    {
        $afterSnapshot = $this->snapshot($after);
        if ($this->comparable($beforeSnapshot) === $this->comparable($afterSnapshot)) {
            return null;
        }

        $type = $this->documentType($after);
        $version = $this->editCount($type, $after->getKey()) + 1;

        return $this->revisions->recordEdit(
            $type,
            $after->getKey(),
            $this->documentNumber($after),
            $this->documentModule($after),
            $version,
            $version + 1,
            $beforeSnapshot,
            $afterSnapshot,
            null,
            auth()->id(),
            $metadata,
        );
    }

    /**
     * Pindahkan riwayat ke dokumen pengganti -- dipakai mutasi stok manual,
     * yang saat diedit diganti dokumen baru bernomor baru. Tanpa ini riwayat
     * edit sebelumnya tertinggal di dokumen lama yang sudah disembunyikan.
     */
    public function moveHistory(Model $from, Model $to): void
    {
        TransactionRevision::query()
            ->where('source_type', $this->documentType($from))
            ->where('source_id', (string) $from->getKey())
            ->update(['source_id' => (string) $to->getKey()]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(string $type, int|string $id): array
    {
        $rows = TransactionRevision::query()
            ->where('source_type', $type)
            ->where('source_id', (string) $id)
            ->where('action', TransactionRevisionAction::EDIT)
            ->orderByDesc('edited_at')
            ->orderByDesc('id')
            ->get();

        $names = $this->userNames($rows);

        return $rows->map(fn (TransactionRevision $row): array => [
            'id' => (int) $row->id,
            'version' => (int) ($row->source_revision_to ?? 0),
            'document_number' => $row->source_number,
            'edited_by' => $row->edited_by !== null ? (int) $row->edited_by : null,
            'edited_by_name' => $names[$row->edited_by] ?? null,
            'edited_at' => $row->edited_at?->toIso8601String(),
            'before' => $row->old_values,
            'after' => $row->new_values,
            'metadata' => $row->metadata,
        ])->values()->all();
    }

    public function documentType(Model $document): string
    {
        return Str::snake(class_basename($document));
    }

    private function editCount(string $type, int|string $id): int
    {
        return TransactionRevision::query()
            ->where('source_type', $type)
            ->where('source_id', (string) $id)
            ->where('action', TransactionRevisionAction::EDIT)
            ->count();
    }

    private function documentNumber(Model $document): ?string
    {
        foreach ($document->getAttributes() as $key => $value) {
            if (str_ends_with($key, '_number') && is_string($value) && $value !== '' && ! str_starts_with($key, 'source_')) {
                return $value;
            }
        }

        return null;
    }

    private function documentModule(Model $document): ?string
    {
        return preg_match('/\\\\Modules\\\\([^\\\\]+)\\\\/', get_class($document), $match) === 1
            ? Str::snake($match[1])
            : null;
    }

    /**
     * Nilai dari database bisa berbeda bentuk untuk isi yang sama ("10" vs
     * "10.0000"); disamakan dulu supaya edit tanpa perubahan tidak tercatat.
     */
    private function comparable(array $snapshot): string
    {
        $normalize = fn (array $row): array => array_map(
            fn ($value) => is_numeric($value) ? (string) (float) $value : $value,
            $row,
        );

        return json_encode([
            $normalize($snapshot['header'] ?? []),
            array_map($normalize, $snapshot['lines'] ?? []),
        ]) ?: '';
    }

    /**
     * @param  Collection<int, TransactionRevision>  $rows
     * @return array<int, string>
     */
    private function userNames(Collection $rows): array
    {
        $ids = $rows->pluck('edited_by')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
