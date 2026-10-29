<?php

namespace Tests\Feature\Permissions;

use App\Modules\Imports\Models\ImportBatch;
use App\Shared\Models\CompanyUser;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Journal\JournalTestCase;

class MatrixRouteEnforcementTest extends JournalTestCase
{
    public function test_transaction_settings_access_does_not_authorize_company_settings(): void
    {
        $ctx = $this->setUpTenant('noaccess');
        $member = CompanyUser::query()->where('company_id', $ctx['company']->id)->firstOrFail();
        $this->assignTestPermissions($member, ['settings.transactions.view', 'settings.transactions.edit']);

        $this->getJson('/api/settings/company', $ctx['headers'])->assertOk()->assertJsonMissingPath('data.profile')->assertJsonMissingPath('data.accounting.base_currency');
        $this->patchJson('/api/settings/company/accounting', ['transaction_edit_mode' => 'open'], $ctx['headers'])->assertOk()->assertJsonMissingPath('data.accounting.base_currency');
        $this->patchJson('/api/settings/company/accounting', ['base_currency' => 'USD'], $ctx['headers'])->assertForbidden();
        $this->patchJson('/api/settings/company/profile', ['name' => 'Changed'], $ctx['headers'])->assertForbidden();
    }

    public function test_create_permission_does_not_grant_editing_a_receipt(): void
    {
        $ctx = $this->setUpTenant('noaccess');
        $member = CompanyUser::query()->where('company_id', $ctx['company']->id)->firstOrFail();
        $this->assignTestPermissions($member, ['sales.receipts.create']);

        $this->patchJson('/api/sales/receipts/1', [], $ctx['headers'])->assertForbidden();
        $this->assignTestPermissions($member, ['sales.receipts.edit']);
        $this->patchJson('/api/sales/receipts/1', [], $ctx['headers'])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_all_route_permission_keys_belong_to_the_matrix(): void
    {
        $keys = config('permissions.matrix_keys', []);
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (str_starts_with($middleware, 'permission:')) {
                    foreach (explode('|', substr($middleware, strlen('permission:'))) as $key) {
                        $this->assertContains($key, $keys, $route->uri());
                    }
                }
            }
        }
    }

    public function test_cash_receipt_permission_does_not_open_cash_payments_or_delete(): void
    {
        $ctx = $this->setUpTenant('noaccess');
        $member = CompanyUser::query()->where('company_id', $ctx['company']->id)->firstOrFail();
        $this->assignTestPermissions($member, ['cash_bank.receipts.view', 'cash_bank.receipts.edit']);

        $this->getJson('/api/cash-bank/cash-receipts', $ctx['headers'])->assertOk();
        $this->getJson('/api/cash-bank/cash-payments', $ctx['headers'])->assertForbidden();
        $this->deleteJson('/api/cash-bank/cash-receipts/1', [], $ctx['headers'])->assertForbidden();
    }

    public function test_sales_report_permission_does_not_open_purchase_or_financial_reports(): void
    {
        $ctx = $this->setUpTenant('noaccess');
        $member = CompanyUser::query()->where('company_id', $ctx['company']->id)->firstOrFail();
        $this->assignTestPermissions($member, ['reports.sales.view']);

        $this->getJson('/api/reports/sales/summary', $ctx['headers'])->assertOk();
        $this->getJson('/api/reports/purchase/summary', $ctx['headers'])->assertForbidden();
        $this->getJson('/api/reports/profit-loss', $ctx['headers'])->assertForbidden();
    }

    public function test_import_access_is_restricted_to_the_granted_profile(): void
    {
        $ctx = $this->setUpTenant('noaccess');
        $member = CompanyUser::query()->where('company_id', $ctx['company']->id)->firstOrFail();
        $this->assignTestPermissions($member, ['sales.invoices.import']);
        $batch = ImportBatch::query()->create([
            'uuid' => (string) Str::uuid(), 'profile' => 'vendor_bill', 'original_filename' => 'test.csv',
            'stored_path' => 'test.csv', 'file_hash' => str_repeat('a', 64), 'status' => 'draft', 'created_by' => $ctx['user']->id,
        ]);

        $this->getJson('/api/imports/transactions/profiles', $ctx['headers'])->assertOk()
            ->assertJsonFragment(['key' => 'sales_invoice'])->assertJsonMissing(['key' => 'vendor_bill']);
        $this->getJson('/api/imports/transactions/templates/vendor_bill', $ctx['headers'])->assertForbidden();
        $this->postJson('/api/imports/transactions/'.$batch->uuid.'/commit', [], $ctx['headers'])->assertForbidden();
        $this->getJson('/api/imports/transactions/batches', $ctx['headers'])->assertOk()->assertJsonMissing(['uuid' => $batch->uuid]);
    }
}
