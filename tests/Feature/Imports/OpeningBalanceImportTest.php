<?php

namespace Tests\Feature\Imports;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\OpeningBalance\Models\OpeningBalanceBatch;
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
use Tests\TestCase;

/**
 * Profil impor saldo awal (temuan Improvement #1). Berbeda dari profil lain:
 * seluruh berkas masuk ke SATU batch saldo awal, bukan satu dokumen per Ref.
 */
class OpeningBalanceImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_fills_one_opening_balance_batch(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $this->commitCsv($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1100', 'Kas awal', '25000000', '0'],
            ['1120', 'Piutang awal', '5000000', '0'],
            ['2100', 'Hutang awal', '0', '10000000'],
            ['3100', 'Modal disetor', '0', '20000000'],
        ]);

        $this->assertSame(1, OpeningBalanceBatch::query()->count());

        $batch = OpeningBalanceBatch::query()->with('lines')->firstOrFail();
        $this->assertSame('draft', $batch->status, 'Impor tidak boleh langsung memposting saldo awal.');
        $this->assertCount(4, $batch->lines);
        $this->assertSame('30000000.00', (string) $batch->total_debit);
        $this->assertSame('30000000.00', (string) $batch->total_credit);
        $this->assertSame('0.00', (string) $batch->difference);
    }

    /**
     * Berkas kedua MENAMBAH ke batch yang sama. `replaceLines()` mengganti
     * seluruh isi batch, jadi tanpa penggabungan eksplisit impor kedua akan
     * menghapus hasil impor pertama.
     */
    public function test_second_import_appends_instead_of_wiping_the_first(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $this->commitCsv($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1100', 'Kas awal', '25000000', '0'],
        ], 'ob-1.csv');

        $this->commitCsv($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['3100', 'Modal disetor', '0', '25000000'],
        ], 'ob-2.csv');

        $this->assertSame(1, OpeningBalanceBatch::query()->count(), 'Tetap satu batch saldo awal.');

        $batch = OpeningBalanceBatch::query()->with('lines')->firstOrFail();
        $this->assertCount(2, $batch->lines);
        $this->assertSame('25000000.00', (string) $batch->total_debit);
        $this->assertSame('25000000.00', (string) $batch->total_credit);
    }

    /**
     * Mengunggah ulang berkas yang sudah dikoreksi TIDAK boleh dilaporkan
     * "berhasil" sambil diam-diam mempertahankan angka lama. Impor menambah,
     * tidak menimpa -- sama seperti kebijakan impor COA.
     */
    public function test_reimporting_an_account_that_already_has_a_line_is_rejected(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $this->commitCsv($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1100', 'Kas awal', '25000000', '0'],
        ], 'ob-1.csv');

        $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1100', 'Kas awal dikoreksi', '30000000', '0'],
        ], 'ob-2.csv');

        $row = ImportRow::query()->latest('id')->firstOrFail();
        $this->assertSame('invalid', $row->status);
        $this->assertStringContainsString('sudah punya baris', json_encode($row->errors));

        // Angka lama tidak berubah, dan tidak ada baris kedua yang menyelinap masuk.
        $batch = OpeningBalanceBatch::query()->with('lines')->firstOrFail();
        $this->assertCount(1, $batch->lines);
        $this->assertSame('25000000.00', (string) $batch->total_debit);
    }

    public function test_nominal_and_parent_and_unknown_accounts_are_rejected(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['4100', 'Pendapatan bukan akun riil', '1000', '0'],
            ['1000', 'Akun induk', '1000', '0'],
            ['9999', 'Akun tidak dikenal', '1000', '0'],
            ['1100', 'Debit dan kredit sekaligus', '1000', '1000'],
        ]);

        $rows = ImportRow::query()->orderBy('row_number')->get();
        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $this->assertSame('invalid', $row->status, "Baris {$row->row_number} seharusnya ditolak.");
        }

        $this->assertSame(0, OpeningBalanceBatch::query()->count());
        $this->assertSame(0, (int) ImportBatch::query()->where('uuid', $uuid)->value('valid_rows'));
    }

    public function test_duplicate_account_code_in_one_file_is_rejected(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1100', 'Kas awal', '10000000', '0'],
            ['1100', 'Kas awal lagi', '5000000', '0'],
        ]);

        $second = ImportRow::query()->orderBy('row_number')->skip(1)->first();
        $this->assertSame('invalid', $second->status);
        $this->assertStringContainsString('lebih dari sekali', json_encode($second->errors));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function commitCsv(array $ctx, array $rows, string $name = 'ob.csv'): void
    {
        $uuid = $this->uploadAndMap($ctx, $rows, $name);
        $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])->assertOk();
    }

    private function uploadAndMap(array $ctx, array $rows, string $name = 'ob.csv'): string
    {
        $batch = $this->postJson('/api/imports', [
            'profile' => 'opening_balance',
            'file' => $this->csvFile($name, $rows),
        ], $ctx['headers'])->assertCreated()->json('data.batch');

        $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => [
                'account_code' => 'Account Code',
                'description' => 'Description',
                'debit' => 'Debit',
                'credit' => 'Credit',
            ],
        ], $ctx['headers'])->assertOk();

        return $batch['uuid'];
    }

    private function seedAccounts(): void
    {
        $parent = ChartOfAccount::query()->create([
            'account_code' => '1000', 'account_name' => 'AKTIVA LANCAR', 'account_type' => 'asset',
            'normal_balance' => 'debit', 'is_active' => true,
        ]);

        foreach ([
            ['1100', 'Kas', 'asset', 'debit', $parent->id],
            ['1120', 'Piutang Usaha', 'asset', 'debit', $parent->id],
            ['2100', 'Hutang Usaha', 'liability', 'credit', null],
            ['3100', 'Modal Disetor', 'equity', 'credit', null],
            ['4100', 'Pendapatan', 'revenue', 'credit', null],
        ] as [$code, $name, $type, $balance, $parentId]) {
            ChartOfAccount::query()->create([
                'account_code' => $code, 'account_name' => $name, 'account_type' => $type,
                'normal_balance' => $balance, 'parent_account_id' => $parentId, 'is_active' => true,
            ]);
        }
    }

    private function setUpTenant(string $role = 'owner'): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'OB Test '.$user->id, 'slug' => 'ob-test-'.$user->id,
            'code' => 'OB-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active', 'created_by' => $user->id,
        ]);
        CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
        $tenantPath = database_path('tenants/test_ob_'.$company->id.'_'.uniqid().'.sqlite');
        File::ensureDirectoryExists(dirname($tenantPath));
        File::put($tenantPath, '');
        $this->registerTenantFile($tenantPath);
        TenantDatabase::query()->create([
            'company_id' => $company->id, 'database_name' => basename($tenantPath),
            'database_path' => $tenantPath, 'driver' => 'sqlite', 'status' => 'active',
        ]);
        app(TenantConnectionManager::class)->connect($tenantPath);
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]);
        Sanctum::actingAs($user, ['*']);

        return ['user' => $user, 'company' => $company, 'headers' => ['X-Company-ID' => (string) $company->id]];
    }

    private function csvFile(string $name, array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ob_csv_');
        $h = fopen($path, 'w');
        foreach ($rows as $r) {
            fputcsv($h, $r);
        }
        fclose($h);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
