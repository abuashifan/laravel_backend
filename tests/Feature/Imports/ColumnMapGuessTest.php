<?php

namespace Tests\Feature\Imports;

use App\Modules\Imports\Services\ImportBatchService;
use Tests\TestCase;

/**
 * Penebakan pemetaan kolom — layar "Petakan Kolom" tidak boleh muncul untuk
 * berkas yang jawabannya sudah bisa disimpulkan.
 */
class ColumnMapGuessTest extends TestCase
{
    private function guess(string $profile, array $headers): array
    {
        return app(ImportBatchService::class)->guessColumnMap($profile, $headers);
    }

    public function test_template_file_maps_completely(): void
    {
        $result = $this->guess('opening_balance', ['Account Code', 'Description', 'Debit', 'Credit']);

        $this->assertSame([], $result['unmapped_required']);
        $this->assertSame([
            'account_code' => 'Account Code',
            'description' => 'Description',
            'debit' => 'Debit',
            'credit' => 'Credit',
        ], $result['map']);
    }

    /**
     * Keluhan yang memicu perubahan ini: berkas klien memakai nama kolom
     * berbahasa Indonesia, dan seluruh layar pemetaan harus diisi tangan.
     */
    public function test_indonesian_headers_map_through_aliases(): void
    {
        $result = $this->guess('opening_balance', ['Kode Akun', 'Keterangan', 'Debit', 'Kredit']);

        $this->assertSame([], $result['unmapped_required']);
        $this->assertSame('Kode Akun', $result['map']['account_code']);
        $this->assertSame('Keterangan', $result['map']['description']);
        $this->assertSame('Kredit', $result['map']['credit']);
    }

    public function test_case_spacing_and_punctuation_are_ignored(): void
    {
        $result = $this->guess('opening_balance', ['  KODE_AKUN ', 'deskripsi', 'DEBET', 'Kredit']);

        $this->assertSame([], $result['unmapped_required']);
        $this->assertSame('  KODE_AKUN ', $result['map']['account_code']);
        $this->assertSame('DEBET', $result['map']['debit']);
    }

    public function test_shuffled_template_columns_still_map(): void
    {
        $result = $this->guess('opening_balance', ['Credit', 'Debit', 'Account Code', 'Description']);

        $this->assertSame([], $result['unmapped_required']);
        $this->assertSame('Account Code', $result['map']['account_code']);
        $this->assertSame('Credit', $result['map']['credit']);
    }

    public function test_field_keys_are_accepted_as_headers(): void
    {
        $result = $this->guess('opening_balance', ['account_code', 'description', 'debit', 'credit']);

        $this->assertSame([], $result['unmapped_required']);
        $this->assertSame('account_code', $result['map']['account_code']);
    }

    /**
     * Kolom yang benar-benar tidak dikenal tetap menyisakan pekerjaan manual —
     * di situlah layar pemetaan masih dibutuhkan.
     */
    public function test_unknown_required_column_is_reported(): void
    {
        $result = $this->guess('opening_balance', ['Kolom Aneh', 'Debit', 'Kredit']);

        $this->assertSame(['account_code'], $result['unmapped_required']);
        $this->assertArrayNotHasKey('account_code', $result['map']);
    }

    /**
     * Satu kolom tidak boleh dipakai dua field. Tanpa penjagaan ini, "Keterangan"
     * bisa terpakai `description` sekaligus `notes` — dan salah satunya diam-diam
     * membaca data milik kolom lain.
     */
    public function test_one_header_is_never_claimed_by_two_fields(): void
    {
        $result = $this->guess('fixed_asset_opening', ['Nama', 'Kategori', 'Tanggal Perolehan', 'Harga Perolehan', 'Keterangan']);

        $used = array_values($result['map']);
        $this->assertSame(count($used), count(array_unique($used)));
    }

    public function test_fixed_asset_template_maps_completely(): void
    {
        $headers = (array) config('imports.profiles.fixed_asset_opening.headers');
        $result = $this->guess('fixed_asset_opening', $headers);

        $this->assertSame([], $result['unmapped_required']);
        $this->assertCount(count($headers), $result['map']);
    }
}
