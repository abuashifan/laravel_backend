<?php

declare(strict_types=1);

namespace Tests\Feature\Setup;

use App\Modules\MasterData\Models\ChartOfAccount;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Journal\JournalTestCase;

class CoaTemplateImportTest extends JournalTestCase
{
    public function test_routes_require_setup_permission(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $this->postJson('/api/setup/coa-templates/import', [
            'file' => $this->csvFile(['Code,Name,Type', '1000,Kas,asset']),
        ], $ctx['headers'])->assertStatus(403);

        $this->getJson('/api/setup/coa-templates/import-template', $ctx['headers'])
            ->assertStatus(403);
    }

    public function test_import_parses_english_headers_and_sorts_parent_first(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        // Anak (1101) sengaja ditulis SEBELUM induknya (1100) di berkas --
        // urutan baris di berkas tidak boleh dipercaya, itu yang diuji di sini.
        $file = $this->csvFile([
            'Code,Name,Type,Parent Code,Cash/Bank',
            '1101,Kas Kecil,asset,1100,yes',
            '1100,Kas & Bank,asset,,no',
        ]);

        $response = $this->postJson('/api/setup/coa-templates/import', [
            'file' => $file,
        ], $ctx['headers'])->assertOk()->json('data');

        $this->assertSame([], $response['skipped']);
        $this->assertCount(2, $response['accounts']);
        $this->assertSame('1100', $response['accounts'][0]['code']);
        $this->assertSame('1101', $response['accounts'][1]['code']);
        $this->assertSame('1100', $response['accounts'][1]['parent_code']);
        $this->assertTrue($response['accounts'][1]['is_cash_bank']);
        $this->assertFalse($response['accounts'][0]['is_cash_bank']);
    }

    public function test_import_recognizes_indonesian_header_aliases(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $file = $this->csvFile([
            'Kode,Nama,Tipe,Induk,Kas/Bank',
            '1000,Kas Kecil,asset,,ya',
        ]);

        $response = $this->postJson('/api/setup/coa-templates/import', [
            'file' => $file,
        ], $ctx['headers'])->assertOk()->json('data');

        $this->assertCount(1, $response['accounts']);
        $this->assertSame('Kas Kecil', $response['accounts'][0]['name']);
        $this->assertTrue($response['accounts'][0]['is_cash_bank']);
    }

    public function test_import_rejects_file_missing_required_headers(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $file = $this->csvFile([
            'Parent Code,Cash/Bank',
            '1100,yes',
        ]);

        $this->postJson('/api/setup/coa-templates/import', [
            'file' => $file,
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'COA_TEMPLATE_IMPORT_HEADERS_NOT_RECOGNIZED');
    }

    public function test_import_skips_duplicate_codes_and_unknown_types(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $file = $this->csvFile([
            'Code,Name,Type,Parent Code,Cash/Bank',
            '1100,Kas,asset,,yes',
            '1100,Kas Duplikat,asset,,no',
            '2000,Akun Aneh,bukan_tipe,,no',
        ]);

        $response = $this->postJson('/api/setup/coa-templates/import', [
            'file' => $file,
        ], $ctx['headers'])->assertOk()->json('data');

        $this->assertCount(1, $response['accounts']);
        $this->assertSame('1100', $response['accounts'][0]['code']);
        $this->assertCount(2, $response['skipped']);
    }

    public function test_import_skips_rows_whose_parent_code_is_missing_from_file(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $file = $this->csvFile([
            'Code,Name,Type,Parent Code,Cash/Bank',
            '1130,Persediaan,asset,1000,no',
        ]);

        $response = $this->postJson('/api/setup/coa-templates/import', [
            'file' => $file,
        ], $ctx['headers'])->assertOk()->json('data');

        $this->assertSame([], $response['accounts']);
        $this->assertCount(1, $response['skipped']);
        $this->assertSame('1130', $response['skipped'][0]['code']);
    }

    public function test_imported_accounts_can_be_applied_via_existing_apply_endpoint(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $file = $this->csvFile([
            'Code,Name,Type,Parent Code,Cash/Bank',
            '1100,Kas & Bank,asset,,no',
            '1101,Kas Kecil,asset,1100,yes',
        ]);

        $imported = $this->postJson('/api/setup/coa-templates/import', [
            'file' => $file,
        ], $ctx['headers'])->assertOk()->json('data');

        $this->postJson('/api/setup/coa-templates/apply', [
            'template_id' => 'blank',
            'accounts' => $imported['accounts'],
        ], $ctx['headers'])->assertOk();

        $this->assertTrue(ChartOfAccount::query()->where('account_code', '1100')->exists());
        $kasKecil = ChartOfAccount::query()->where('account_code', '1101')->firstOrFail();
        $this->assertTrue((bool) $kasKecil->is_cash_bank);
    }

    public function test_import_template_downloads_a_spreadsheet(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $response = $this->get('/api/setup/coa-templates/import-template', $ctx['headers']);

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));
    }

    /** @param  list<string>  $lines  Baris CSV LENGKAP termasuk header, sudah dipisah koma. */
    private function csvFile(array $lines): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'coa_import_');
        file_put_contents($path, implode("\n", $lines)."\n");

        return new UploadedFile($path, 'coa.csv', 'text/csv', null, true);
    }
}
