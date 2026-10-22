<?php

namespace Tests\Feature\Imports;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\MasterData\Models\Contact;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\TenantConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Satu berkas .xlsx berisi banyak sheet; user memilih sheet yang diimpor.
 * Bawaannya tetap sheet pertama, supaya templat unduhan (sheet kedua
 * "Referensi") dan semua berkas lama berperilaku sama seperti sebelumnya.
 */
class ImportSheetSelectionTest extends TestCase
{
    use RefreshDatabase;

    private const SHEETS = [
        'Catatan' => [
            ['Keterangan'],
            ['Sheet ini bukan data'],
        ],
        'Pelanggan Lama' => [
            ['Code', 'Name', 'Type'],
            ['OLD-001', 'Pelanggan Lama', 'customer'],
        ],
        'Pelanggan Baru' => [
            ['Code', 'Name', 'Type'],
            ['NEW-001', 'Pelanggan Baru A', 'customer'],
            ['NEW-002', 'Pelanggan Baru B', 'customer'],
        ],
    ];

    public function test_without_sheet_the_first_sheet_is_read_and_all_sheets_are_listed(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $data = $this->upload($ctx, self::SHEETS)->assertCreated()->json('data');

        $this->assertSame(['Catatan', 'Pelanggan Lama', 'Pelanggan Baru'], $data['sheets']);
        $this->assertSame(['Keterangan'], $data['headers']);
        $this->assertNull($data['batch']['sheet_name']);
    }

    public function test_the_last_sheet_can_be_chosen_at_upload_and_is_used_through_commit(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $data = $this->upload($ctx, self::SHEETS, 'Pelanggan Baru')->assertCreated()->json('data');

        $this->assertSame(['Code', 'Name', 'Type'], $data['headers']);
        $this->assertSame('Pelanggan Baru', $data['batch']['sheet_name']);
        $this->assertSame(2, $data['batch']['total_rows']);
        $this->assertTrue($data['auto_mapped']);

        $uuid = $data['batch']['uuid'];

        $preview = $this->patchJson("/api/imports/{$uuid}/mapping", [
            'column_map' => $data['suggested_column_map'],
        ], $ctx['headers'])->assertOk()->json('data');
        $this->assertSame(2, $preview['valid_rows']);

        // Buka ulang batch: header & sheet yang sama, bukan sheet pertama.
        $shown = $this->getJson("/api/imports/{$uuid}", $ctx['headers'])->assertOk()->json('data');
        $this->assertSame('Pelanggan Baru', $shown['sheet_name']);
        $this->assertSame(['Code', 'Name', 'Type'], $shown['headers']);
        $this->assertSame(['Catatan', 'Pelanggan Lama', 'Pelanggan Baru'], $shown['sheets']);

        $this->postJson("/api/imports/{$uuid}/commit", [], $ctx['headers'])->assertOk();

        $this->assertSame(
            ['NEW-001', 'NEW-002'],
            Contact::query()->orderBy('contact_code')->pluck('contact_code')->all()
        );
    }

    public function test_choosing_the_first_sheet_by_name_is_stored_as_the_default(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $data = $this->upload($ctx, self::SHEETS, 'Catatan')->assertCreated()->json('data');

        $this->assertNull($data['batch']['sheet_name']);
        $this->assertSame(['Keterangan'], $data['headers']);
    }

    public function test_unknown_sheet_at_upload_is_rejected_with_the_available_sheets(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $response = $this->upload($ctx, self::SHEETS, 'Tidak Ada')->assertStatus(422);

        $this->assertStringContainsString("Sheet 'Tidak Ada' tidak ada di berkas", (string) $response->json('message'));
        $this->assertStringContainsString('Catatan, Pelanggan Lama, Pelanggan Baru', (string) $response->json('message'));
        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_switching_sheet_resets_mapping_and_preview(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $uuid = $this->upload($ctx, self::SHEETS, 'Pelanggan Lama')->assertCreated()->json('data.batch.uuid');
        $this->patchJson("/api/imports/{$uuid}/mapping", [
            'column_map' => ['code' => 'Code', 'name' => 'Name', 'type' => 'Type'],
        ], $ctx['headers'])->assertOk();

        $batch = ImportBatch::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(1, $batch->rows()->count());

        $switched = $this->patchJson("/api/imports/{$uuid}/sheet", ['sheet' => 'Pelanggan Baru'], $ctx['headers'])
            ->assertOk()
            ->json('data');

        $this->assertSame('Pelanggan Baru', $switched['sheet_name']);
        $this->assertSame('draft', $switched['status']);
        $this->assertNull($switched['column_map']);
        $this->assertSame(2, $switched['total_rows']);
        $this->assertSame(['Code', 'Name', 'Type'], $switched['headers']);
        $this->assertSame(0, $batch->rows()->count());

        $preview = $this->patchJson("/api/imports/{$uuid}/mapping", [
            'column_map' => $switched['suggested_column_map'],
        ], $ctx['headers'])->assertOk()->json('data');
        $this->assertSame(2, $preview['valid_rows']);
    }

    public function test_switching_to_an_empty_sheet_is_rejected_and_keeps_the_batch(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $sheets = self::SHEETS + ['Kosong' => [['Code', 'Name', 'Type']]];
        $uuid = $this->upload($ctx, $sheets, 'Pelanggan Lama')->assertCreated()->json('data.batch.uuid');

        $response = $this->patchJson("/api/imports/{$uuid}/sheet", ['sheet' => 'Kosong'], $ctx['headers'])
            ->assertStatus(422);

        $this->assertStringContainsString("Sheet 'Kosong' harus memiliki minimal satu baris data", (string) $response->json('message'));
        $this->assertSame('Pelanggan Lama', ImportBatch::query()->where('uuid', $uuid)->value('sheet_name'));
    }

    public function test_switching_sheet_is_rejected_after_commit(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $data = $this->upload($ctx, self::SHEETS, 'Pelanggan Baru')->assertCreated()->json('data');
        $uuid = $data['batch']['uuid'];
        $this->patchJson("/api/imports/{$uuid}/mapping", ['column_map' => $data['suggested_column_map']], $ctx['headers'])->assertOk();
        $this->postJson("/api/imports/{$uuid}/commit", [], $ctx['headers'])->assertOk();

        $this->patchJson("/api/imports/{$uuid}/sheet", ['sheet' => 'Pelanggan Lama'], $ctx['headers'])->assertStatus(422);
    }

    public function test_csv_has_no_sheets_and_cannot_switch(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $path = tempnam(sys_get_temp_dir(), 'import_csv_');
        $handle = fopen($path, 'w');
        fputcsv($handle, ['Code', 'Name', 'Type']);
        fputcsv($handle, ['C-1', 'PT CSV', 'customer']);
        fclose($handle);

        $data = $this->postJson('/api/imports', [
            'profile' => 'contact',
            'file' => new UploadedFile($path, 'kontak.csv', 'text/csv', null, true),
            // Diabaikan untuk CSV.
            'sheet' => 'Apa Saja',
        ], $ctx['headers'])->assertCreated()->json('data');

        $this->assertSame([], $data['sheets']);
        $this->assertNull($data['batch']['sheet_name']);

        $this->patchJson("/api/imports/{$data['batch']['uuid']}/sheet", ['sheet' => 'Sheet1'], $ctx['headers'])
            ->assertStatus(422);
    }

    public function test_tiered_routes_expose_the_sheet_endpoint(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $uuid = $this->upload($ctx, self::SHEETS)->assertCreated()->json('data.batch.uuid');

        $this->patchJson("/api/imports/master/{$uuid}/sheet", ['sheet' => 'Pelanggan Baru'], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.sheet_name', 'Pelanggan Baru');
    }

    /**
     * @param  array<string, list<list<string>>>  $sheets
     */
    private function upload(array $ctx, array $sheets, ?string $sheet = null)
    {
        $payload = ['profile' => 'contact', 'file' => $this->xlsxFile($sheets)];
        if ($sheet !== null) {
            $payload['sheet'] = $sheet;
        }

        return $this->postJson('/api/imports', $payload, $ctx['headers']);
    }

    /**
     * @param  array<string, list<list<string>>>  $sheets
     */
    private function xlsxFile(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import_xlsx_');
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $title => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray($rows);
        }

        // Sheet aktif sengaja BUKAN sheet pertama: bawaan harus tetap sheet
        // pertama, bukan sheet yang kebetulan terbuka saat berkas disimpan.
        $spreadsheet->setActiveSheetIndex($spreadsheet->getSheetCount() - 1);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'kontak.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function setUpTenant(string $role = 'owner'): array
    {
        $user = User::factory()->create(['status' => 'active']);

        $company = Company::query()->create([
            'name' => 'Company Sheet '.$user->id,
            'slug' => 'company-sheet-'.$user->id,
            'code' => 'CMP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $tenantPath = database_path('tenants/test_company_'.$company->id.'_'.uniqid().'.sqlite');
        File::ensureDirectoryExists(dirname($tenantPath));
        File::put($tenantPath, '');
        $this->registerTenantFile($tenantPath);

        TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => basename($tenantPath),
            'database_path' => $tenantPath,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        app(TenantConnectionManager::class)->connect($tenantPath);

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        return [
            'user' => $user,
            'company' => $company,
            'headers' => ['X-Company-ID' => (string) $company->id],
            'tenant_path' => $tenantPath,
        ];
    }
}
