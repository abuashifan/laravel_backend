<?php

namespace Tests\Unit;

use App\Services\AccountMapping\AccountMappingService;
use App\Services\AccountMapping\AccountMappingValidator;
use App\Support\AccountMapping\AccountMappingKey;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

class AccountMappingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure config is available in the test process even when config caching behavior changes.
        Config::set('account_mappings', require config_path('account_mappings.php'));
    }

    public function test_all_requirements_returns_configured_mappings(): void
    {
        $service = $this->app->make(AccountMappingService::class);
        $req = $service->allRequirements();

        $this->assertNotEmpty($req);
    }

    public function test_sales_accounts_receivable_exists_and_required(): void
    {
        $service = $this->app->make(AccountMappingService::class);
        $this->assertTrue($service->exists(AccountMappingKey::SALES_ACCOUNTS_RECEIVABLE));
        $this->assertTrue($service->isRequired(AccountMappingKey::SALES_ACCOUNTS_RECEIVABLE));
    }

    public function test_sales_discount_is_optional(): void
    {
        $service = $this->app->make(AccountMappingService::class);
        $this->assertFalse($service->isRequired(AccountMappingKey::SALES_DISCOUNT));
    }

    public function test_module_for_sales_revenue_returns_sales(): void
    {
        $this->assertSame('sales', AccountMappingKey::moduleFor(AccountMappingKey::SALES_REVENUE));
    }

    public function test_keys_for_module_sales_contains_sales_revenue(): void
    {
        $keys = AccountMappingKey::keysForModule('sales');
        $this->assertContains(AccountMappingKey::SALES_REVENUE, $keys);
    }

    public function test_required_keys_for_sales_contains_required_pairs(): void
    {
        $service = $this->app->make(AccountMappingService::class);
        $keys = $service->requiredKeys('sales');

        $this->assertContains(AccountMappingKey::SALES_ACCOUNTS_RECEIVABLE, $keys);
        $this->assertContains(AccountMappingKey::SALES_REVENUE, $keys);
    }

    public function test_validate_required_keys_passes_when_required_keys_provided(): void
    {
        $service = $this->app->make(AccountMappingService::class);

        $res = $service->validateRequiredKeys([
            AccountMappingKey::SALES_ACCOUNTS_RECEIVABLE,
            AccountMappingKey::SALES_REVENUE,
        ], 'sales');

        $this->assertTrue($res['valid']);
    }

    public function test_validate_required_keys_fails_when_required_keys_missing(): void
    {
        $service = $this->app->make(AccountMappingService::class);

        $res = $service->validateRequiredKeys([
            AccountMappingKey::SALES_REVENUE,
        ], 'sales');

        $this->assertFalse($res['valid']);
        $this->assertNotEmpty($res['missing']);
    }

    public function test_validate_account_type_accepts_asset_for_sales_accounts_receivable(): void
    {
        $service = $this->app->make(AccountMappingService::class);
        $res = $service->validateAccountTypeForKey(AccountMappingKey::SALES_ACCOUNTS_RECEIVABLE, 'asset');

        $this->assertTrue($res['valid']);
    }

    public function test_validate_account_type_rejects_expense_for_sales_accounts_receivable(): void
    {
        $service = $this->app->make(AccountMappingService::class);
        $res = $service->validateAccountTypeForKey(AccountMappingKey::SALES_ACCOUNTS_RECEIVABLE, 'expense');

        $this->assertFalse($res['valid']);
    }

    public function test_unknown_mapping_key_returns_error(): void
    {
        $service = $this->app->make(AccountMappingService::class);
        $res = $service->validateAccountTypeForKey('unknown.key', 'asset');

        $this->assertFalse($res['valid']);
    }

    public function test_unknown_module_returns_error(): void
    {
        $validator = $this->app->make(AccountMappingValidator::class);
        $res = $validator->validateModuleRequirements('unknown', []);

        $this->assertFalse($res['valid']);
        $this->assertContains('UNKNOWN_MAPPING_MODULE', $res['errors']);
    }

    public function test_future_get_account_id_throws_until_storage_implemented(): void
    {
        $service = $this->app->make(AccountMappingService::class);
        $this->expectException(RuntimeException::class);
        $service->getAccountId(AccountMappingKey::SALES_REVENUE);
    }
}
