<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Modules\MasterData\Models\AccountMapping;
use Illuminate\Http\UploadedFile;

class AccountMappingImportTest extends MasterDataTestCase
{
    public function test_routes_require_permission(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $this->postJson('/api/master-data/account-mappings/import', [
            'file' => $this->csvFile(['Mapping Key,Account Code', 'sales.accounts_receivable,1100']),
        ], $ctx['headers'])->assertStatus(403);

        $this->getJson('/api/master-data/account-mappings/import-template', $ctx['headers'])
            ->assertStatus(403);
    }

    public function test_import_rejects_file_missing_required_headers(): void
    {
        $ctx = $this->setUpTenant();

        $file = $this->csvFile([
            'Foo,Bar',
            'baz,qux',
        ]);

        $this->postJson('/api/master-data/account-mappings/import', [
            'file' => $file,
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ACCOUNT_MAPPING_IMPORT_HEADERS_NOT_RECOGNIZED');
    }

    public function test_import_applies_valid_rows_and_leaves_blank_rows_untouched(): void
    {
        $ctx = $this->setUpTenant();

        $ar = $this->postJson('/api/master-data/chart-of-accounts', [
            'account_code' => '1100',
            'account_name' => 'Piutang Usaha',
            'account_type' => 'asset',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $deposit = $this->postJson('/api/master-data/chart-of-accounts', [
            'account_code' => '2130',
            'account_name' => 'Uang Muka Pelanggan',
            'account_type' => 'liability',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        // Mapping ini sudah diisi manual SEBELUM impor -- baris impornya
        // sengaja mengosongkan Account Code untuk key ini, jadi harus tetap
        // menunjuk ke akun manual, bukan ikut terhapus.
        $this->patchJson('/api/master-data/account-mappings/sales.customer_deposit', [
            'account_id' => $deposit['id'],
        ], $ctx['headers'])->assertOk();

        $file = $this->csvFile([
            'Mapping Key,Account Code',
            'sales.accounts_receivable,1100',
            'sales.customer_deposit,',
        ]);

        $response = $this->postJson('/api/master-data/account-mappings/import', [
            'file' => $file,
        ], $ctx['headers'])->assertOk()->json('data');

        $this->assertSame(1, $response['applied_count']);
        $this->assertSame(1, $response['skipped_count']);
        $this->assertSame(0, $response['error_count']);

        $this->assertSame($ar['id'], AccountMapping::query()->where('mapping_key', 'sales.accounts_receivable')->value('account_id'));
        $this->assertSame($deposit['id'], AccountMapping::query()->where('mapping_key', 'sales.customer_deposit')->value('account_id'));
    }

    public function test_import_reports_unknown_mapping_key_and_unknown_account_code_as_errors(): void
    {
        $ctx = $this->setUpTenant();

        $file = $this->csvFile([
            'Mapping Key,Account Code',
            'bukan.key.valid,1100',
            'sales.accounts_receivable,9999',
        ]);

        $response = $this->postJson('/api/master-data/account-mappings/import', [
            'file' => $file,
        ], $ctx['headers'])->assertOk()->json('data');

        $this->assertSame(0, $response['applied_count']);
        $this->assertSame(2, $response['error_count']);
        $this->assertNull(AccountMapping::query()->where('mapping_key', 'sales.accounts_receivable')->value('account_id'));
    }

    public function test_import_reports_wrong_account_type_and_parent_account_as_row_errors_without_aborting_batch(): void
    {
        $ctx = $this->setUpTenant();

        $revenue = $this->postJson('/api/master-data/chart-of-accounts', [
            'account_code' => '4100',
            'account_name' => 'Penjualan',
            'account_type' => 'revenue',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $parent = $this->postJson('/api/master-data/chart-of-accounts', [
            'account_code' => '1100',
            'account_name' => 'Piutang Usaha',
            'account_type' => 'asset',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->postJson('/api/master-data/chart-of-accounts', [
            'account_code' => '1100.01',
            'account_name' => 'Piutang Usaha - Sub',
            'account_type' => 'asset',
            'parent_account_id' => $parent['id'],
        ], $ctx['headers'])->assertStatus(201);

        $cash = $this->postJson('/api/master-data/chart-of-accounts', [
            'account_code' => '1000',
            'account_name' => 'Kas',
            'account_type' => 'asset',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $file = $this->csvFile([
            'Mapping Key,Account Code',
            'sales.accounts_receivable,4100',
            'sales.revenue,1100',
            'cash_bank.default_cash,1000',
        ]);

        $response = $this->postJson('/api/master-data/account-mappings/import', [
            'file' => $file,
        ], $ctx['headers'])->assertOk()->json('data');

        $this->assertSame(1, $response['applied_count']);
        $this->assertSame(2, $response['error_count']);
        $this->assertSame($cash['id'], AccountMapping::query()->where('mapping_key', 'cash_bank.default_cash')->value('account_id'));
        $this->assertNull(AccountMapping::query()->where('mapping_key', 'sales.accounts_receivable')->value('account_id'));
        $this->assertNull(AccountMapping::query()->where('mapping_key', 'sales.revenue')->value('account_id'));
    }

    public function test_import_template_downloads_a_spreadsheet(): void
    {
        $ctx = $this->setUpTenant();

        $response = $this->get('/api/master-data/account-mappings/import-template', $ctx['headers']);

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));
    }

    /** @param  list<string>  $lines  Baris CSV LENGKAP termasuk header, sudah dipisah koma. */
    private function csvFile(array $lines): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'account_mapping_import_');
        file_put_contents($path, implode("\n", $lines)."\n");

        return new UploadedFile($path, 'account-mapping.csv', 'text/csv', null, true);
    }
}
