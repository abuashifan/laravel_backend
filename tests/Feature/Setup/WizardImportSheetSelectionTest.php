<?php

declare(strict_types=1);

namespace Tests\Feature\Setup;

use App\Modules\MasterData\Models\AccountMapping;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Journal\JournalTestCase;

/**
 * Impor berkas di wizard Setup (COA dan pemetaan akun) membaca berkas dalam
 * satu permintaan, tanpa batch. Pilihan sheet harus sampai ke sana juga --
 * sebelumnya keduanya terkunci di sheet pertama.
 */
class WizardImportSheetSelectionTest extends JournalTestCase
{
    private const COA_SHEETS = [
        'Catatan' => [['Keterangan'], ['Bukan data']],
        'COA Lama' => [['Code', 'Name', 'Type'], ['9000', 'Akun Lama', 'asset']],
        'COA Baru' => [['Code', 'Name', 'Type'], ['1000', 'Kas', 'asset'], ['2000', 'Utang', 'liability']],
    ];

    public function test_coa_import_defaults_to_first_sheet(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $response = $this->postJson('/api/setup/coa-templates/import', [
            'file' => $this->xlsx(self::COA_SHEETS),
        ], $ctx['headers']);

        // Sheet pertama ("Catatan") bukan data akun: header Code/Name/Type tidak ada.
        $response->assertStatus(422)->assertJsonPath('code', 'COA_TEMPLATE_IMPORT_HEADERS_NOT_RECOGNIZED');
    }

    public function test_coa_import_reads_the_chosen_sheet(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $data = $this->postJson('/api/setup/coa-templates/import', [
            'file' => $this->xlsx(self::COA_SHEETS),
            'sheet' => 'COA Baru',
        ], $ctx['headers'])->assertOk()->json('data');

        $this->assertSame(['1000', '2000'], array_column($data['accounts'], 'code'));
        $this->assertSame([], $data['skipped']);
    }

    public function test_coa_import_rejects_unknown_sheet_with_available_sheets(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $response = $this->postJson('/api/setup/coa-templates/import', [
            'file' => $this->xlsx(self::COA_SHEETS),
            'sheet' => 'Tidak Ada',
        ], $ctx['headers'])->assertStatus(422);

        $this->assertStringContainsString("Sheet 'Tidak Ada' tidak ada di berkas", (string) $response->json('message'));
        $this->assertStringContainsString('Catatan, COA Lama, COA Baru', (string) $response->json('message'));
    }

    public function test_coa_sheet_listing_returns_names_in_order_and_empty_for_csv(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $this->postJson('/api/setup/coa-templates/import/sheets', [
            'file' => $this->xlsx(self::COA_SHEETS),
        ], $ctx['headers'])->assertOk()->assertJsonPath('data.sheets', ['Catatan', 'COA Lama', 'COA Baru']);

        $csv = tempnam(sys_get_temp_dir(), 'coa_csv_');
        file_put_contents($csv, "Code,Name,Type\n1000,Kas,asset\n");

        $this->postJson('/api/setup/coa-templates/import/sheets', [
            'file' => new UploadedFile($csv, 'coa.csv', 'text/csv', null, true),
        ], $ctx['headers'])->assertOk()->assertJsonPath('data.sheets', []);
    }

    public function test_sheet_listing_requires_setup_edit_permission(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $this->postJson('/api/setup/coa-templates/import/sheets', [
            'file' => $this->xlsx(self::COA_SHEETS),
        ], $ctx['headers'])->assertStatus(403);
    }

    public function test_account_mapping_import_reads_the_chosen_sheet(): void
    {
        $ctx = $this->setUpTenant();

        $ar = $this->postJson('/api/master-data/chart-of-accounts', [
            'account_code' => '1100',
            'account_name' => 'Piutang Usaha',
            'account_type' => 'asset',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $sheets = [
            'Catatan' => [['Keterangan'], ['Bukan data']],
            'Pemetaan' => [['Mapping Key', 'Account Code'], ['sales.accounts_receivable', '1100']],
        ];

        $this->postJson('/api/master-data/account-mappings/import/sheets', [
            'file' => $this->xlsx($sheets),
        ], $ctx['headers'])->assertOk()->assertJsonPath('data.sheets', ['Catatan', 'Pemetaan']);

        // Tanpa sheet: sheet pertama tidak punya kolom yang dikenali, dan tidak ada yang diterapkan.
        $this->postJson('/api/master-data/account-mappings/import', [
            'file' => $this->xlsx($sheets),
        ], $ctx['headers'])->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_MAPPING_IMPORT_HEADERS_NOT_RECOGNIZED');
        $this->assertNull(AccountMapping::query()->where('mapping_key', 'sales.accounts_receivable')->value('account_id'));

        $data = $this->postJson('/api/master-data/account-mappings/import', [
            'file' => $this->xlsx($sheets),
            'sheet' => 'Pemetaan',
        ], $ctx['headers'])->assertOk()->json('data');

        $this->assertSame(1, $data['applied_count']);
        $this->assertSame($ar['id'], AccountMapping::query()->where('mapping_key', 'sales.accounts_receivable')->value('account_id'));
    }

    /**
     * @param  array<string, list<list<string>>>  $sheets
     */
    private function xlsx(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wizard_xlsx_');
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $title => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray($rows);
        }

        $spreadsheet->setActiveSheetIndex($spreadsheet->getSheetCount() - 1);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'berkas.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
