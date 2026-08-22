<?php

namespace Tests\Feature\Imports;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Models\Product;
use App\Modules\MasterData\Models\ProductCategory;
use App\Modules\MasterData\Models\Unit;
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
 * Commit tiga profil master data (rencana impor data, Fase 1) — kontak,
 * produk, COA — lewat service dokumen yang sudah ada, bukan menulis model
 * langsung.
 */
class MasterDataImportCommitTest extends TestCase
{
    use RefreshDatabase;

    // ── Contact ──────────────────────────────────────────────────────────

    public function test_contact_valid_row_commits_through_the_service(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $batch = $this->upload($ctx, 'contact', [
            ['Code', 'Name', 'Type', 'Email', 'Phone', 'Address', 'Tax Number'],
            ['CUST-001', 'PT Contoh Relasi', 'customer', 'finance@example.test', '021-123456', 'Jakarta', ''],
        ]);

        $this->mapContact($ctx, $batch['uuid']);

        $res = $this->postJson('/api/imports/'.$batch['uuid'].'/commit', [], $ctx['headers'])->assertOk();
        $res->assertJsonPath('data.committed_rows', 1);
        $res->assertJsonPath('data.status', 'completed');

        $contact = Contact::query()->where('contact_code', 'CUST-001')->firstOrFail();
        $this->assertSame('PT Contoh Relasi', $contact->name);
        $this->assertSame('customer', $contact->contact_type);
        $this->assertTrue((bool) $contact->is_customer);

        $row = $this->rowOf($batch['uuid']);
        $this->assertSame('committed', $row->status);
        $this->assertSame($contact->id, $row->document_id);
        $this->assertSame(Contact::class, $row->document_type);
    }

    public function test_contact_duplicate_code_in_database_is_invalid_at_preview(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        Contact::query()->create(['contact_code' => 'CUST-001', 'name' => 'Sudah Ada']);

        $batch = $this->upload($ctx, 'contact', [
            ['Code', 'Name', 'Type', 'Email', 'Phone', 'Address', 'Tax Number'],
            ['CUST-001', 'PT Baru', 'customer', '', '', '', ''],
        ]);

        $res = $this->mapContact($ctx, $batch['uuid']);
        $res->assertJsonPath('data.valid_rows', 0);
        $res->assertJsonPath('data.failed_rows', 1);
        $this->assertStringContainsString('sudah dipakai', $this->rowOf($batch['uuid'])->errors['code'][0]);
    }

    public function test_contact_duplicate_code_within_the_same_file_is_invalid(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $batch = $this->upload($ctx, 'contact', [
            ['Code', 'Name', 'Type', 'Email', 'Phone', 'Address', 'Tax Number'],
            ['CUST-001', 'PT Satu', 'customer', '', '', '', ''],
            ['CUST-001', 'PT Dua', 'customer', '', '', '', ''],
        ]);

        $res = $this->mapContact($ctx, $batch['uuid']);
        $res->assertJsonPath('data.valid_rows', 1);
        $res->assertJsonPath('data.failed_rows', 1);
    }

    // ── Product ──────────────────────────────────────────────────────────

    public function test_product_valid_row_resolves_category_and_unit_by_lookup(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $category = ProductCategory::query()->create(['name' => 'Alat Tulis Kantor', 'is_active' => true]);
        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);

        $batch = $this->upload($ctx, 'product', [
            ['Code', 'Name', 'Type', 'Category', 'Unit', 'Stock Item', 'Min Stock'],
            ['PRD-001', 'Kertas A4', 'goods', 'Alat Tulis Kantor', 'PCS', 'yes', '10'],
        ]);

        $this->mapProduct($ctx, $batch['uuid']);
        $this->postJson('/api/imports/'.$batch['uuid'].'/commit', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.committed_rows', 1);

        $product = Product::query()->where('product_code', 'PRD-001')->firstOrFail();
        $this->assertSame($category->id, $product->product_category_id);
        $this->assertSame($unit->id, $product->unit_id);
        $this->assertTrue((bool) $product->is_stock_item);
    }

    public function test_product_unknown_category_and_unit_report_the_value_in_the_error(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $batch = $this->upload($ctx, 'product', [
            ['Code', 'Name', 'Type', 'Category', 'Unit', 'Stock Item', 'Min Stock'],
            ['PRD-001', 'Kertas A4', 'goods', 'Kategori Fiktif', 'UNIT-FIKTIF', 'yes', '0'],
        ]);

        $res = $this->mapProduct($ctx, $batch['uuid']);
        $res->assertJsonPath('data.failed_rows', 1);

        $errors = $this->rowOf($batch['uuid'])->errors;
        $this->assertStringContainsString('Kategori Fiktif', $errors['category'][0]);
        $this->assertStringContainsString('UNIT-FIKTIF', $errors['unit'][0]);
    }

    // ── Chart of Account ─────────────────────────────────────────────────

    public function test_coa_child_listed_before_parent_in_file_still_commits_with_correct_hierarchy(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        // Anak (1101) muncul SEBELUM induknya (1100) di berkas.
        $batch = $this->upload($ctx, 'chart_of_account', [
            ['Code', 'Name', 'Type', 'Parent Code', 'Cash/Bank'],
            ['1101', 'Kas Kecil', 'asset', '1100', 'yes'],
            ['1100', 'Kas & Bank', 'asset', '', ''],
        ]);

        $res = $this->mapCoa($ctx, $batch['uuid']);
        $res->assertJsonPath('data.valid_rows', 2);
        $res->assertJsonPath('data.failed_rows', 0);

        $commit = $this->postJson('/api/imports/'.$batch['uuid'].'/commit', [], $ctx['headers'])->assertOk();
        $commit->assertJsonPath('data.committed_rows', 2);

        $parent = ChartOfAccount::query()->where('account_code', '1100')->firstOrFail();
        $child = ChartOfAccount::query()->where('account_code', '1101')->firstOrFail();
        $this->assertSame($parent->id, $child->parent_account_id);
    }

    public function test_coa_existing_code_is_rejected_not_overwritten(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $existing = ChartOfAccount::query()->create([
            'account_code' => '1100', 'account_name' => 'Kas & Bank Lama', 'account_type' => 'asset', 'normal_balance' => 'debit',
        ]);

        $batch = $this->upload($ctx, 'chart_of_account', [
            ['Code', 'Name', 'Type', 'Parent Code', 'Cash/Bank'],
            ['1100', 'Kas & Bank Baru', 'asset', '', ''],
        ]);

        $res = $this->mapCoa($ctx, $batch['uuid']);
        $res->assertJsonPath('data.failed_rows', 1);
        $this->assertSame('Kas & Bank Lama', $existing->fresh()->account_name);
    }

    public function test_coa_parent_code_not_found_anywhere_is_invalid(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $batch = $this->upload($ctx, 'chart_of_account', [
            ['Code', 'Name', 'Type', 'Parent Code', 'Cash/Bank'],
            ['1101', 'Kas Kecil', 'asset', '9999', ''],
        ]);

        $res = $this->mapCoa($ctx, $batch['uuid']);
        $res->assertJsonPath('data.failed_rows', 1);
        $this->assertStringContainsString('9999', $this->rowOf($batch['uuid'])->errors['parent_code'][0]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function upload(array $ctx, string $profile, array $rows): array
    {
        return $this->postJson('/api/imports', [
            'profile' => $profile,
            'file' => $this->csvFile($profile.'.csv', $rows),
        ], $ctx['headers'])->assertCreated()->json('data.batch');
    }

    private function mapContact(array $ctx, string $uuid)
    {
        return $this->patchJson('/api/imports/'.$uuid.'/mapping', [
            'column_map' => ['code' => 'Code', 'name' => 'Name', 'type' => 'Type', 'email' => 'Email', 'phone' => 'Phone', 'address' => 'Address', 'tax_number' => 'Tax Number'],
        ], $ctx['headers'])->assertOk();
    }

    private function mapProduct(array $ctx, string $uuid)
    {
        return $this->patchJson('/api/imports/'.$uuid.'/mapping', [
            'column_map' => ['code' => 'Code', 'name' => 'Name', 'type' => 'Type', 'category' => 'Category', 'unit' => 'Unit', 'stock_item' => 'Stock Item', 'min_stock' => 'Min Stock'],
        ], $ctx['headers'])->assertOk();
    }

    private function mapCoa(array $ctx, string $uuid)
    {
        return $this->patchJson('/api/imports/'.$uuid.'/mapping', [
            'column_map' => ['code' => 'Code', 'name' => 'Name', 'type' => 'Type', 'parent_code' => 'Parent Code', 'cash_bank' => 'Cash/Bank'],
        ], $ctx['headers'])->assertOk();
    }

    private function rowOf(string $uuid): ImportRow
    {
        return ImportBatch::query()->where('uuid', $uuid)->firstOrFail()
            ->rows()->orderBy('row_number')->firstOrFail();
    }

    private function setUpTenant(string $role = 'owner'): array
    {
        $user = User::factory()->create(['status' => 'active']);

        $company = Company::query()->create([
            'name' => 'Company Import '.$user->id,
            'slug' => 'company-import-'.$user->id,
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

        $tenantPath = database_path('tenants/test_masterimport_'.$company->id.'_'.uniqid().'.sqlite');
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
