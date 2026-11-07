<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosCategory;
use App\Modules\Pos\Models\PosProduct;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Impor produk dari Excel (XLSX) atau CSV. Satu jalur untuk pratinjau dan
 * simpan: berkas yang sama dibaca ulang, jadi tidak ada berkas tersimpan.
 *
 * Baris dicocokkan ke produk lama lewat SKU, lalu barcode, lalu nama persis.
 * Produk lama: sel kosong = nilai lama dipertahankan, stok TIDAK diubah
 * (pakai stok masuk / opname supaya mutasinya tercatat). Produk baru: kolom
 * Stok terisi = stok dilacak dan stok awal dicatat sebagai mutasi.
 */
class PosProductImportService
{
    public const MAX_ROWS = 1000;

    /** Judul kolom templat (juga dipakai ekspor) => kunci internal. */
    public const COLUMNS = [
        'Nama' => 'name',
        'SKU' => 'sku',
        'Barcode' => 'barcode',
        'Kategori' => 'category',
        'Satuan' => 'unit',
        'Harga jual' => 'price',
        'Harga modal' => 'cost',
        'Stok' => 'stock',
        'Stok minimum' => 'min_stock',
    ];

    /** Judul lain yang juga dikenali (huruf kecil, tanpa spasi ganda). */
    private const ALIASES = [
        'nama produk' => 'name', 'nama barang' => 'name', 'produk' => 'name',
        'kode' => 'sku', 'kode produk' => 'sku', 'kode barang' => 'sku',
        'kode barcode' => 'barcode',
        'harga' => 'price', 'harga jual' => 'price',
        'modal' => 'cost', 'harga beli' => 'cost', 'hpp' => 'cost',
        'stok awal' => 'stock', 'jumlah stok' => 'stock', 'qty' => 'stock',
        'unit' => 'unit',
        'stok min' => 'min_stock', 'min stok' => 'min_stock', 'minimum stok' => 'min_stock',
    ];

    public function __construct(
        private readonly PosStockService $stock,
        private readonly PosPlanService $plans,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, summary: array{create: int, update: int, error: int}, committed: bool}
     */
    public function run(string $path, string $extension, bool $commit, ?Company $company, ?int $userId): array
    {
        $rows = $this->plan($this->read($path, $extension), $company);

        if ($commit) {
            DB::connection('pos')->transaction(function () use (&$rows, $userId) {
                $categories = [];
                foreach ($rows as &$row) {
                    if ($row['action'] !== 'error') {
                        $row['product_id'] = $this->apply($row, $categories, $userId);
                    }
                }
                unset($row);
            });
        }

        $summary = ['create' => 0, 'update' => 0, 'error' => 0];
        foreach ($rows as $row) {
            $summary[$row['action']]++;
        }

        return [
            'rows' => array_map(fn ($row) => array_diff_key($row, ['values' => true, 'existing' => true]), $rows),
            'summary' => $summary,
            'committed' => $commit,
        ];
    }

    /**
     * Baca berkas jadi baris `[nomor baris => [kunci => teks]]`.
     *
     * @return array<int, array<string, string>>
     */
    private function read(string $path, string $extension): array
    {
        $extension = strtolower($extension);
        try {
            $table = match ($extension) {
                'csv', 'txt' => $this->readCsv($path),
                'xlsx', 'xls' => IOFactory::load($path)->getSheet(0)->toArray(null, true, false, false),
                default => null,
            };
        } catch (Throwable) {
            $table = false;
        }
        if ($table === null) {
            throw $this->invalid('Format berkas harus XLSX atau CSV.');
        }
        if ($table === false || $table === []) {
            throw $this->invalid('Berkas tidak bisa dibaca atau kosong.');
        }

        $keys = array_map(fn ($header) => $this->columnKey((string) ($header ?? '')), array_shift($table));
        if (! in_array('name', $keys, true) || ! in_array('price', $keys, true)) {
            throw $this->invalid('Baris pertama harus berisi judul kolom, minimal "Nama" dan "Harga jual". Unduh templat dari halaman impor.');
        }

        $rows = [];
        foreach ($table as $index => $cells) {
            $values = [];
            foreach ($keys as $column => $key) {
                if ($key !== null && ! isset($values[$key])) {
                    $cell = $cells[$column] ?? null;
                    // Angka asli dari XLSX dipakai apa adanya, tidak ditebak pemisahnya.
                    $values[$key] = in_array($key, ['price', 'cost', 'stock', 'min_stock'], true) && (is_int($cell) || is_float($cell))
                        ? (float) $cell
                        : $this->cellText($cell);
                }
            }
            if (implode('', $values) === '') {
                continue;
            }
            $rows[$index + 2] = $values;
        }
        if ($rows === []) {
            throw $this->invalid('Tidak ada baris produk di berkas.');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw $this->invalid('Maksimal '.self::MAX_ROWS.' baris per impor. Pecah berkas menjadi beberapa bagian.');
        }

        return $rows;
    }

    /**
     * Sel angka dari XLSX ditulis dengan titik desimal tanpa pemisah ribuan
     * dan tanpa notasi E (barcode 13 digit tersimpan sebagai angka di Excel).
     */
    private function cellText(mixed $cell): string
    {
        if (is_float($cell) || is_int($cell)) {
            return floor((float) $cell) === (float) $cell
                ? sprintf('%.0f', $cell)
                : rtrim(rtrim(number_format((float) $cell, 6, '.', ''), '0'), '.');
        }

        return trim((string) ($cell ?? ''));
    }

    /** CSV dari Excel berbahasa Indonesia memakai titik koma, bukan koma. */
    private function readCsv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $table = [];
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $table[] = $row;
        }
        fclose($handle);

        return $table;
    }

    private function columnKey(string $header): ?string
    {
        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', str_replace('*', '', $header))));
        foreach (self::COLUMNS as $title => $key) {
            if (strtolower($title) === $normalized) {
                return $key;
            }
        }

        return self::ALIASES[$normalized] ?? null;
    }

    /**
     * Tentukan tindakan tiap baris tanpa menyimpan apa pun.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return list<array<string, mixed>>
     */
    private function plan(array $rows, ?Company $company): array
    {
        $products = PosProduct::query()->get(['id', 'name', 'sku', 'barcode']);
        $bySku = $products->filter(fn ($p) => $p->sku !== null)->keyBy(fn ($p) => strtolower($p->sku));
        $byBarcode = $products->filter(fn ($p) => $p->barcode !== null)->keyBy('barcode');
        $byName = $products->keyBy(fn ($p) => strtolower($p->name));

        $limit = $company ? $this->plans->planForCompany($company)?->max_products : null;
        $room = $limit === null ? PHP_INT_MAX : max(0, $limit - $products->count());

        $seenSku = [];
        $seenBarcode = [];
        $planned = [];

        foreach ($rows as $number => $values) {
            $errors = [];
            $name = $values['name'] ?? '';
            $sku = ($values['sku'] ?? '') !== '' ? $values['sku'] : null;
            $barcode = ($values['barcode'] ?? '') !== '' ? $values['barcode'] : null;

            $existing = ($sku !== null ? $bySku->get(strtolower($sku)) : null)
                ?? ($barcode !== null ? $byBarcode->get($barcode) : null)
                ?? ($name !== '' ? $byName->get(strtolower($name)) : null);

            if ($name === '' && ! $existing) {
                $errors[] = 'Nama wajib diisi.';
            }
            if (mb_strlen($name) > 150) {
                $errors[] = 'Nama maksimal 150 huruf.';
            }
            if ($sku !== null && mb_strlen($sku) > 50) {
                $errors[] = 'SKU maksimal 50 huruf.';
            }
            if ($barcode !== null && mb_strlen($barcode) > 64) {
                $errors[] = 'Barcode maksimal 64 huruf.';
            }
            if (mb_strlen($values['category'] ?? '') > 100) {
                $errors[] = 'Kategori maksimal 100 huruf.';
            }
            if (mb_strlen($values['unit'] ?? '') > 20) {
                $errors[] = 'Satuan maksimal 20 huruf.';
            }

            $numbers = [];
            foreach (['price' => 'Harga jual', 'cost' => 'Harga modal', 'stock' => 'Stok', 'min_stock' => 'Stok minimum'] as $key => $label) {
                $raw = $values[$key] ?? '';
                if ($raw === '') {
                    continue;
                }
                $raw = is_float($raw) ? $raw : (string) $raw;
                $parsed = is_float($raw) ? $raw : self::parseNumber($raw);
                if ($parsed === null || $parsed < 0) {
                    $errors[] = "{$label} \"{$raw}\" bukan angka yang benar.";
                } else {
                    $numbers[$key] = $parsed;
                }
            }
            if (! $existing && ($values['price'] ?? '') === '') {
                $errors[] = 'Harga jual wajib diisi untuk produk baru.';
            }

            // SKU / barcode tidak boleh milik produk lain, atau dipakai dua kali di berkas.
            if ($sku !== null) {
                $owner = $bySku->get(strtolower($sku));
                if ($owner && $existing && $owner->id !== $existing->id) {
                    $errors[] = "SKU {$sku} sudah dipakai produk {$owner->name}.";
                }
                if (isset($seenSku[strtolower($sku)])) {
                    $errors[] = "SKU {$sku} dipakai lagi di baris {$seenSku[strtolower($sku)]}.";
                }
            }
            if ($barcode !== null) {
                $owner = $byBarcode->get($barcode);
                if ($owner && (! $existing || $owner->id !== $existing->id)) {
                    $errors[] = "Barcode {$barcode} sudah dipakai produk {$owner->name}.";
                }
                if (isset($seenBarcode[$barcode])) {
                    $errors[] = "Barcode {$barcode} dipakai lagi di baris {$seenBarcode[$barcode]}.";
                }
            }

            if ($errors === [] && ! $existing) {
                if ($room <= 0) {
                    $errors[] = "Melebihi batas paket ({$limit} produk).";
                } else {
                    $room--;
                }
            }

            if ($errors === []) {
                if ($sku !== null) {
                    $seenSku[strtolower($sku)] = $number;
                }
                if ($barcode !== null) {
                    $seenBarcode[$barcode] = $number;
                }
            }

            $planned[] = [
                'row' => $number,
                'name' => $name !== '' ? $name : ($existing->name ?? ''),
                'action' => $errors !== [] ? 'error' : ($existing ? 'update' : 'create'),
                'errors' => $errors,
                'notes' => $existing && isset($numbers['stock']) && $errors === []
                    ? ['Stok produk lama tidak diubah. Pakai Stok masuk atau Stok opname.']
                    : [],
                'values' => [
                    'name' => $name,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'category' => $values['category'] ?? '',
                    'unit' => $values['unit'] ?? '',
                ] + $numbers,
                'existing' => $existing?->id,
            ];
        }

        return $planned;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $categories
     */
    private function apply(array $row, array &$categories, ?int $userId): int
    {
        $values = $row['values'];
        $data = [];
        foreach (['name', 'sku', 'barcode', 'unit'] as $key) {
            if ($values[$key] !== null && $values[$key] !== '') {
                $data[$key] = $values[$key];
            }
        }
        foreach (['price', 'cost', 'min_stock'] as $key) {
            if (isset($values[$key])) {
                $data[$key] = $values[$key];
            }
        }
        if ($values['category'] !== '') {
            $key = strtolower($values['category']);
            $categories[$key] ??= PosCategory::query()->whereRaw('lower(name) = ?', [$key])->value('id')
                ?? PosCategory::query()->create(['name' => $values['category']])->id;
            $data['category_id'] = $categories[$key];
        }

        if ($row['existing']) {
            $product = PosProduct::query()->findOrFail($row['existing']);
            $product->update($data);

            return $product->id;
        }

        $tracked = isset($values['stock']);
        $product = PosProduct::query()->create($data + ['unit' => 'pcs', 'cost' => 0, 'stock' => 0, 'track_stock' => $tracked, 'is_active' => true]);
        if ($tracked && $values['stock'] > 0) {
            $this->stock->move($product, (float) $values['stock'], 'adjustment', null, null, 'Stok awal (impor)', $userId);
        }

        return $product->id;
    }

    /**
     * Angka gaya Indonesia atau Inggris: "Rp 3.500", "3.500,50", "3,500.50",
     * "3500". Pemisah terakhir yang diikuti 1-2 digit = desimal; titik/koma
     * yang memisahkan kelompok 3 digit = ribuan.
     */
    public static function parseNumber(string $raw): ?float
    {
        $value = preg_replace('/[^\d.,\-]/', '', $raw) ?? '';
        if ($value === '' || ! preg_match('/\d/', $value)) {
            return null;
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        if (str_contains($value, '-')) {
            return null;
        }

        $lastDot = strrpos($value, '.');
        $lastComma = strrpos($value, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $decimal = $lastDot > $lastComma ? '.' : ',';
        } elseif ($lastDot !== false || $lastComma !== false) {
            $separator = $lastDot !== false ? '.' : ',';
            $groups = explode($separator, $value);
            $thousands = count($groups) > 1 && $groups[0] !== '' && strlen($groups[0]) <= 3
                && collect(array_slice($groups, 1))->every(fn ($group) => strlen($group) === 3);
            $decimal = $thousands ? null : $separator;
            if ($decimal !== null && count($groups) > 2) {
                return null;
            }
        } else {
            $decimal = null;
        }

        $thousand = $decimal === '.' ? ',' : ($decimal === ',' ? '.' : null);
        if ($thousand !== null) {
            $value = str_replace($thousand, '', $value);
        } else {
            $value = str_replace(['.', ','], '', $value);
        }
        if ($decimal !== null) {
            $value = str_replace($decimal, '.', $value);
        }
        if (! is_numeric($value)) {
            return null;
        }

        return round(($negative ? -1 : 1) * (float) $value, 3);
    }

    private function invalid(string $message): ApiException
    {
        return ApiException::make('VALIDATION_ERROR', $message, 422, ['file' => [$message]]);
    }
}
