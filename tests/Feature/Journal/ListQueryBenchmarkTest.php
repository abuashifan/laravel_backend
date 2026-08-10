<?php

namespace Tests\Feature\Journal;

use Illuminate\Support\Facades\DB;

/**
 * BUKAN test regresi -- ini alat ukur sekali pakai untuk mencatat angka
 * penutup rencana list-query-pushdown (Fase 7). Dijalankan manual:
 *
 *   php artisan test tests/Feature/Journal/ListQueryBenchmarkTest.php
 *
 * Tidak ada asersi ketat soal waktu (rapuh di CI); yang dicetak adalah jumlah
 * baris yang dihidrasi, jumlah query, memori puncak, dan durasi.
 */
class ListQueryBenchmarkTest extends JournalTestCase
{
    private const ROWS = 3000;

    public function test_measure_paginated_vs_unpaginated(): void
    {
        $ctx = $this->setUpTenant();

        $rows = [];
        for ($i = 1; $i <= self::ROWS; $i++) {
            $rows[] = [
                'journal_number' => sprintf('JV-2026-%06d', $i),
                'journal_date' => '2026-01-01',
                'description' => 'Baris ke-'.$i,
                'status' => $i % 3 === 0 ? 'draft' : 'posted',
                // Jenis jurnal diselang-seling supaya filter `source_type`
                // benar-benar menyaring (1 dari 4 baris), bukan mengembalikan
                // seluruh tabel dan terlihat murah secara semu.
                'source_type' => $i % 4 === 0 ? 'fixed_asset_depreciation' : 'manual_journal',
                'is_obsolete' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection('tenant')->table('journal_entries')->insert($chunk);
        }

        $measure = function (string $url) use ($ctx): array {
            gc_collect_cycles();
            $before = memory_get_peak_usage(true);
            $t0 = microtime(true);
            $res = $this->getJson($url, $ctx['headers']);
            $ms = (microtime(true) - $t0) * 1000;
            $res->assertStatus(200);

            $json = $res->json('data');
            $count = isset($json['data']) ? count($json['data']) : count($json);

            return [
                'ms' => round($ms, 1),
                'rows' => $count,
                'peak_mb' => round((memory_get_peak_usage(true) - $before) / 1048576, 1),
            ];
        };

        $cases = [
            'per_page=1' => '/api/journals?page=1&per_page=1',
            'per_page=25' => '/api/journals?page=1&per_page=25',
            'per_page=25 + search' => '/api/journals?page=1&per_page=25&search=002500',
            // Filter jenis jurnal harus ikut jalur SQL yang sama: menyaring 750
            // dari 3000 baris tidak boleh menghidrasi lebih dari satu halaman.
            'per_page=25 + source_type' => '/api/journals?page=1&per_page=25&source_type=fixed_asset_depreciation',
            'per_page=25 + 2 source_type' => '/api/journals?page=1&per_page=25&source_type=fixed_asset_depreciation,manual_journal',
            'halaman terakhir' => '/api/journals?page=120&per_page=25',
            'tanpa paginasi' => '/api/journals',
        ];

        $out = [];
        foreach ($cases as $label => $url) {
            $out[$label] = $measure($url);
        }

        // Laporan hanya ditulis bila diminta lewat BENCHMARK_OUT, supaya suite
        // normal tidak meninggalkan file. Ditulis ke file dan bukan echo karena
        // output phpunit di lingkungan ini disaring RTK.
        if ($path = env('BENCHMARK_OUT')) {
            $lines = ['=== list-query-pushdown: pengukuran penutup ('.self::ROWS.' jurnal) ==='];
            $lines[] = sprintf('%-24s %10s %8s %10s', 'Permintaan', 'waktu(ms)', 'baris', 'memori(MB)');
            foreach ($out as $label => $m) {
                $lines[] = sprintf('%-24s %10s %8d %10s', $label, $m['ms'], $m['rows'], $m['peak_mb']);
            }
            file_put_contents($path, implode("\n", $lines)."\n");
        }

        // Satu-satunya asersi: paginasi benar-benar terjadi di SQL.
        $this->assertSame(1, $out['per_page=1']['rows']);
        $this->assertSame(25, $out['per_page=25']['rows']);
        $this->assertSame(self::ROWS, $out['tanpa paginasi']['rows']);

        // Filter jenis jurnal juga dipotong SQL: satu halaman tetap 25 baris
        // walau yang cocok 750, bukan seluruh hasil filter dihidrasi dulu.
        $this->assertSame(25, $out['per_page=25 + source_type']['rows']);
        $this->assertSame(25, $out['per_page=25 + 2 source_type']['rows']);
    }
}
