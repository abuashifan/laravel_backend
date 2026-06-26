<?php

namespace Tests\Unit;

use App\Contracts\Transactions\TransactionDateGuard;
use App\Contracts\Transactions\TransactionDependencyChecker;
use App\Models\CompanyAccountingSetting;
use App\Models\Company;
use App\Services\Permissions\PermissionService;
use App\Services\Settings\CompanySettingService;
use App\Services\Tenant\TenantContext;
use App\Services\Transactions\TransactionPolicyService;
use App\Support\Transaction\DependencyCheckResult;
use App\Support\Transaction\TransactionAction;
use App\Support\Transaction\TransactionPolicyResult;
use Tests\TestCase;

class TransactionPolicyServiceTest extends TestCase
{
    private function makeService(
        array $grantedPermissions,
        array $settingsOverrides = [],
        bool $hasDependency = false,
        ?TransactionPolicyResult $dateGuardResult = null,
    ): TransactionPolicyService {
        $setting = new CompanyAccountingSetting(array_merge([
            'allow_edit_transactions' => true,
            'allow_edit_posted_transactions' => true,
            'allow_void_transactions' => true,
            'auto_post_transactions' => true,
            'date_warning_enabled' => true,
            'block_outside_current_fiscal_year' => true,
        ], $settingsOverrides));

        $tenantContext = new class(new Company(['id' => 1, 'name' => 'Test Company'])) extends TenantContext {
            public function __construct(private readonly Company $activeCompany)
            {
            }

            public function company(): ?\App\Models\Company
            {
                return $this->activeCompany;
            }
        };

        $companySettingService = new class($setting) extends CompanySettingService {
            public function __construct(private readonly CompanyAccountingSetting $setting)
            {
            }

            public function getOrCreateAccountingSetting(\App\Models\Company $company): CompanyAccountingSetting
            {
                return $this->setting;
            }
        };

        $permissionService = new class($grantedPermissions) extends PermissionService {
            public function __construct(private readonly array $grantedPermissions)
            {
            }

            public function cannot(string $permission): bool
            {
                return ! in_array($permission, $this->grantedPermissions, true);
            }
        };

        $dependencyChecker = new class($hasDependency) implements TransactionDependencyChecker {
            public function __construct(private readonly bool $hasDependency)
            {
            }

            public function check(mixed $transaction, string $action, string $module): DependencyCheckResult
            {
                return $this->hasDependency
                    ? DependencyCheckResult::blocked([['reason' => 'blocking']])
                    : DependencyCheckResult::clear();
            }

            public function hasBlockingDependencies(mixed $transaction, string $action, string $module): bool
            {
                return $this->hasDependency;
            }

            public function blockingReasons(mixed $transaction, string $action, string $module): array
            {
                return [['reason' => 'blocking']];
            }
        };

        $dateGuard = new class($dateGuardResult ?? TransactionPolicyResult::allow()) implements TransactionDateGuard {
            public function __construct(private readonly TransactionPolicyResult $result)
            {
            }

            public function check(?string $transactionDate, string $action, string $module): TransactionPolicyResult
            {
                return $this->result;
            }
        };

        return new TransactionPolicyService(
            $tenantContext,
            $companySettingService,
            $permissionService,
            $dependencyChecker,
            $dateGuard,
        );
    }

    public function test_can_create_sales_when_user_has_sales_create_permission(): void
    {
        $service = $this->makeService(['sales.create']);
        $result = $service->canCreate('sales', '2026-05-17');

        $this->assertTrue($result->allowed());
        $this->assertFalse($result->isWarning());
    }

    public function test_cannot_create_sales_without_sales_create_permission(): void
    {
        $service = $this->makeService([]);
        $result = $service->canCreate('sales', '2026-05-17');

        $this->assertTrue($result->denied());
    }

    public function test_can_edit_draft_transaction_when_settings_allow_and_permission_exists(): void
    {
        $service = $this->makeService(['sales.edit']);
        $result = $service->canEdit('sales', ['status' => 'draft', 'transaction_date' => '2026-05-17']);

        $this->assertTrue($result->allowed());
    }

    public function test_can_edit_posted_transaction_when_allow_edit_posted_true(): void
    {
        $service = $this->makeService(['sales.edit'], ['allow_edit_posted_transactions' => true]);
        $result = $service->canEdit('sales', ['status' => 'posted', 'transaction_date' => '2026-05-17']);

        $this->assertTrue($result->allowed());
    }

    public function test_cannot_edit_posted_transaction_when_allow_edit_posted_false(): void
    {
        $service = $this->makeService(['sales.edit'], ['allow_edit_posted_transactions' => false]);
        $result = $service->canEdit('sales', ['status' => 'posted', 'transaction_date' => '2026-05-17']);

        $this->assertTrue($result->denied());
        $this->assertSame('COMPANY_SETTING_EDIT_POSTED_DISABLED', $result->toArray()['code']);
    }

    public function test_cannot_edit_void_transaction(): void
    {
        $service = $this->makeService(['sales.edit']);
        $result = $service->canEdit('sales', ['status' => 'void', 'transaction_date' => '2026-05-17']);

        $this->assertTrue($result->denied());
        $this->assertSame('TRANSACTION_ALREADY_VOID', $result->toArray()['code']);
    }

    public function test_can_void_posted_transaction_when_settings_allow_and_permission_exists(): void
    {
        $service = $this->makeService(['sales.void'], ['allow_void_transactions' => true]);
        $result = $service->canVoid('sales', ['status' => 'posted', 'transaction_date' => '2026-05-17']);

        $this->assertTrue($result->allowed());
    }

    public function test_cannot_void_when_allow_void_transactions_false(): void
    {
        $service = $this->makeService(['sales.void'], ['allow_void_transactions' => false]);
        $result = $service->canVoid('sales', ['status' => 'posted', 'transaction_date' => '2026-05-17']);

        $this->assertTrue($result->denied());
        $this->assertSame('COMPANY_SETTING_VOID_DISABLED', $result->toArray()['code']);
    }

    public function test_cannot_void_transaction_with_dependencies(): void
    {
        $service = $this->makeService(['sales.void'], [], true);
        $result = $service->canVoid('sales', ['status' => 'posted', 'transaction_date' => '2026-05-17']);

        $this->assertTrue($result->denied());
        $this->assertSame('TRANSACTION_HAS_DEPENDENCY', $result->toArray()['code']);
    }

    public function test_inventory_edit_is_blocked_when_generated_stock_movements_exist(): void
    {
        $service = $this->makeService(['inventory.manage']);
        $result = $service->canEdit('inventory', [
            'status' => 'draft',
            'transaction_date' => '2026-05-17',
            'stock_movement_id' => 15,
        ]);

        $this->assertTrue($result->denied());
        $this->assertSame('TRANSACTION_HAS_DEPENDENCY', $result->toArray()['code']);
    }

    public function test_can_view_void_transaction_if_user_has_view_permission(): void
    {
        $service = $this->makeService(['sales.view']);
        $result = $service->canView('sales', ['status' => 'void', 'transaction_date' => '2026-05-17']);

        $this->assertTrue($result->allowed());
    }

    public function test_posted_transaction_is_editable_by_lifecycle_but_can_be_blocked_by_company_setting(): void
    {
        $service = $this->makeService(['sales.edit'], ['allow_edit_posted_transactions' => false]);
        $result = $service->canEdit('sales', ['status' => 'posted', 'transaction_date' => '2026-05-17']);

        $this->assertTrue($result->denied());
    }

    public function test_date_guard_warning_is_returned_as_warning_result(): void
    {
        $service = $this->makeService(['sales.create'], [], false, TransactionPolicyResult::warning(
            'TRANSACTION_DATE_WARNING',
            'Date warning',
        ));

        $result = $service->check('sales', TransactionAction::CREATE, null, '2026-05-17');
        $this->assertTrue($result->allowed());
        $this->assertTrue($result->isWarning());
        $this->assertSame('TRANSACTION_DATE_WARNING', $result->toArray()['code']);
    }

    public function test_date_guard_blocked_returns_deny_result(): void
    {
        $service = $this->makeService(['sales.create'], [], false, TransactionPolicyResult::deny(
            'TRANSACTION_DATE_BLOCKED',
            'Date blocked',
        ));

        $result = $service->check('sales', TransactionAction::CREATE, null, '2026-05-17');
        $this->assertTrue($result->denied());
        $this->assertSame('TRANSACTION_DATE_BLOCKED', $result->toArray()['code']);
    }

    public function test_unknown_module_action_is_denied_consistently(): void
    {
        $service = $this->makeService(['sales.create']);
        $result = $service->check('unknown', 'create', null, '2026-05-17');

        $this->assertTrue($result->denied());
        $this->assertSame('UNKNOWN_TRANSACTION_MODULE', $result->toArray()['code']);
    }

    public function test_policy_result_to_array_returns_expected_structure(): void
    {
        $result = TransactionPolicyResult::deny('PERMISSION_DENIED', 'No', ['x'], ['k' => 'v']);
        $arr = $result->toArray();

        $this->assertSame(
            ['allowed', 'warning', 'code', 'message', 'reasons', 'meta'],
            array_keys($arr)
        );
        $this->assertFalse($arr['allowed']);
        $this->assertFalse($arr['warning']);
        $this->assertSame('PERMISSION_DENIED', $arr['code']);
        $this->assertSame(['x'], $arr['reasons']);
        $this->assertSame(['k' => 'v'], $arr['meta']);
    }
}
