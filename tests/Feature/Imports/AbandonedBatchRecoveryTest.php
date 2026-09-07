<?php

namespace Tests\Feature\Imports;

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
 * Batch yang diunggah lalu ditinggalkan — halaman di-reload sebelum commit.
 *
 * UUID-nya cuma hidup di state frontend, jadi begitu halaman dimuat ulang batch
 * itu jadi yatim: ia masih memblokir unggahan berikutnya lewat
 * `ensureNoActiveBatch()`, tapi tidak ada layar yang bisa membukanya kembali.
 * Riwayat impor harus cukup untuk melanjutkan ATAU membuang batch itu.
 */
class AbandonedBatchRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_headers_so_an_abandoned_draft_can_be_reopened(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $uuid = $this->uploadOpeningBalance($ctx);

        $data = $this->getJson("/api/imports/{$uuid}", $ctx['headers'])
            ->assertOk()
            ->json('data');

        // Tanpa `headers` layar pemetaan tidak punya isi dropdown sama sekali.
        $this->assertSame(['Kode Akun', 'Keterangan', 'Debit', 'Kredit'], $data['headers']);
        $this->assertSame('Kode Akun', $data['suggested_column_map']['account_code']);
        $this->assertSame('draft', $data['status']);
    }

    public function test_history_lists_the_abandoned_draft(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $uuid = $this->uploadOpeningBalance($ctx);

        $this->getJson('/api/imports?page=1&per_page=25', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.data.0.uuid', $uuid)
            ->assertJsonPath('data.data.0.status', 'draft');
    }

    public function test_an_abandoned_draft_can_be_deleted_from_history(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $uuid = $this->uploadOpeningBalance($ctx);

        $this->deleteJson("/api/imports/{$uuid}", [], $ctx['headers'])->assertOk();

        $this->assertDatabaseMissing('import_batches', ['uuid' => $uuid], 'tenant');
    }

    /**
     * Batch `previewed` — sudah dipetakan, tinggal commit — MEMBLOKIR unggahan
     * berikutnya (`imports.active_statuses`). Ini kasus buntu yang sebenarnya:
     * tanpa tombol di riwayat, satu batch yang ditinggalkan mengunci seluruh
     * menu impor sampai ada yang menyentuh basis datanya langsung.
     */
    public function test_deleting_a_previewed_batch_releases_the_active_batch_lock(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $uuid = $this->uploadOpeningBalance($ctx);
        $this->patchJson("/api/imports/{$uuid}/mapping", [
            'column_map' => [
                'account_code' => 'Kode Akun',
                'description' => 'Keterangan',
                'debit' => 'Debit',
                'credit' => 'Kredit',
            ],
        ], $ctx['headers'])->assertOk();

        $this->postJson('/api/imports', [
            'profile' => 'opening_balance',
            'file' => $this->csvFile('saldo-awal-2.csv', $this->openingBalanceRows('1200', 'Bank', '7000000')),
        ], $ctx['headers'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'IMPORT_ACTIVE_BATCH_EXISTS')
            ->assertJsonPath('meta.batch_uuid', $uuid);

        $this->deleteJson("/api/imports/{$uuid}", [], $ctx['headers'])->assertOk();

        $this->postJson('/api/imports', [
            'profile' => 'opening_balance',
            'file' => $this->csvFile('saldo-awal-2.csv', $this->openingBalanceRows('1200', 'Bank', '7000000')),
        ], $ctx['headers'])->assertCreated();
    }

    /** Batch `previewed` yang dibuka lagi harus membawa pemetaan yang tersimpan. */
    public function test_reopened_previewed_batch_carries_its_saved_column_map(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $uuid = $this->uploadOpeningBalance($ctx);
        $this->patchJson("/api/imports/{$uuid}/mapping", [
            'column_map' => [
                'account_code' => 'Kode Akun',
                'description' => 'Keterangan',
                'debit' => 'Debit',
                'credit' => 'Kredit',
            ],
        ], $ctx['headers'])->assertOk();

        $data = $this->getJson("/api/imports/{$uuid}", $ctx['headers'])->assertOk()->json('data');

        $this->assertSame('previewed', $data['status']);
        $this->assertSame('Kode Akun', $data['column_map']['account_code']);
        $this->assertSame(['Kode Akun', 'Keterangan', 'Debit', 'Kredit'], $data['headers']);
    }

    public function test_show_survives_a_batch_whose_stored_file_is_gone(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $uuid = $this->uploadOpeningBalance($ctx);
        $stored = $this->getJson("/api/imports/{$uuid}", $ctx['headers'])->json('data.stored_path');
        Storage::disk('local')->delete($stored);

        $this->getJson("/api/imports/{$uuid}", $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.headers', []);
    }

    /** @return list<array<int, string>> */
    private function openingBalanceRows(string $code = '1100', string $name = 'Kas', string $debit = '5000000'): array
    {
        return [
            ['Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            [$code, $name, $debit, '0'],
        ];
    }

    private function uploadOpeningBalance(array $ctx): string
    {
        return (string) $this->postJson('/api/imports', [
            'profile' => 'opening_balance',
            'file' => $this->csvFile('saldo-awal.csv', $this->openingBalanceRows()),
        ], $ctx['headers'])->assertCreated()->json('data.batch.uuid');
    }

    private function setUpTenant(): array
    {
        $user = User::factory()->create(['status' => 'active']);

        $company = Company::query()->create([
            'name' => 'Company Recovery '.$user->id,
            'slug' => 'company-recovery-'.$user->id,
            'code' => 'CMR-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
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

    private function csvFile(string $name, array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import_csv_');
        $handle = fopen($path, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
