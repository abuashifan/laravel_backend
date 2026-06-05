<?php

namespace App\Services\Transactions;

use App\Contracts\Transactions\TransactionDateGuard;
use App\Contracts\Transactions\TransactionDependencyChecker;
use App\Services\Permissions\PermissionService;
use App\Services\Settings\CompanySettingService;
use App\Services\Tenant\TenantContext;
use App\Support\Transaction\TransactionAction;
use App\Support\Transaction\TransactionLifecycle;
use App\Support\Transaction\TransactionModule;
use App\Support\Transaction\TransactionPolicyResult;
use App\Support\Transaction\TransactionStatus;

class TransactionPolicyService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CompanySettingService $companySettingService,
        private readonly PermissionService $permissionService,
        private readonly TransactionDependencyChecker $dependencyChecker,
        private readonly TransactionDateGuard $dateGuard,
    ) {
    }

    public function canView(string $module, mixed $transaction = null): TransactionPolicyResult
    {
        return $this->check($module, TransactionAction::VIEW, $transaction);
    }

    public function canCreate(string $module, ?string $transactionDate = null): TransactionPolicyResult
    {
        return $this->check($module, TransactionAction::CREATE, null, $transactionDate);
    }

    public function canEdit(string $module, mixed $transaction): TransactionPolicyResult
    {
        return $this->check($module, TransactionAction::EDIT, $transaction);
    }

    public function canVoid(string $module, mixed $transaction): TransactionPolicyResult
    {
        return $this->check($module, TransactionAction::VOID, $transaction);
    }

    public function canApprove(string $module, mixed $transaction): TransactionPolicyResult
    {
        return $this->check($module, TransactionAction::APPROVE, $transaction);
    }

    public function canPost(string $module, mixed $transaction): TransactionPolicyResult
    {
        return $this->check($module, TransactionAction::POST, $transaction);
    }

    public function check(
        string $module,
        string $action,
        mixed $transaction = null,
        ?string $transactionDate = null
    ): TransactionPolicyResult {
        // 1) Validate module/action
        $permissionResult = $this->resolvePermission($module, $action);
        if ($permissionResult instanceof TransactionPolicyResult) {
            return $permissionResult;
        }
        $permission = $permissionResult;

        // 2) Permission check
        if ($this->permissionService->cannot($permission)) {
            return TransactionPolicyResult::deny('PERMISSION_DENIED', 'Permission denied.', [
                'permission' => $permission,
            ]);
        }

        // 3) Company setting
        $company = $this->tenantContext->company();
        $settings = null;
        if ($company) {
            $settings = $this->companySettingService->getOrCreateAccountingSetting($company);
        }

        $allowEditTransactions = (bool) ($settings?->allow_edit_transactions ?? true);
        $allowEditPostedTransactions = (bool) ($settings?->allow_edit_posted_transactions ?? true);
        $allowVoidTransactions = (bool) ($settings?->allow_void_transactions ?? true);

        // 4/5) Lifecycle + dependency (transaction required for some actions)
        if (in_array($action, [TransactionAction::EDIT, TransactionAction::VOID, TransactionAction::APPROVE, TransactionAction::POST], true)) {
            $status = $this->getTransactionStatus($transaction);

            if (! $status) {
                return TransactionPolicyResult::deny('TRANSACTION_STATUS_MISSING', 'Transaction status is missing.');
            }

            if ($status === TransactionStatus::VOID) {
                return TransactionPolicyResult::deny('TRANSACTION_ALREADY_VOID', 'Transaction already void.');
            }

            if ($action === TransactionAction::EDIT) {
                if (! TransactionLifecycle::isEditableStatus($status)) {
                    return TransactionPolicyResult::deny('TRANSACTION_STATUS_NOT_EDITABLE', 'Transaction status is not editable.');
                }
                if (! $allowEditTransactions) {
                    return TransactionPolicyResult::deny('COMPANY_SETTING_EDIT_DISABLED', 'Company setting disallows editing transactions.');
                }
                if ($status === TransactionStatus::POSTED && ! $allowEditPostedTransactions) {
                    return TransactionPolicyResult::deny('COMPANY_SETTING_EDIT_POSTED_DISABLED', 'Company setting disallows editing posted transactions.');
                }
            }

            if ($action === TransactionAction::VOID) {
                if (! TransactionLifecycle::isVoidableStatus($status)) {
                    return TransactionPolicyResult::deny('TRANSACTION_STATUS_NOT_VOIDABLE', 'Transaction status is not voidable.');
                }
                if (! $allowVoidTransactions) {
                    return TransactionPolicyResult::deny('COMPANY_SETTING_VOID_DISABLED', 'Company setting disallows voiding transactions.');
                }
            }

            if ($action === TransactionAction::POST && $status === TransactionStatus::POSTED) {
                return TransactionPolicyResult::deny('TRANSACTION_ALREADY_POSTED', 'Transaction already posted.');
            }

            if ($action === TransactionAction::APPROVE && in_array($status, [TransactionStatus::APPROVED, TransactionStatus::POSTED], true)) {
                return TransactionPolicyResult::deny('TRANSACTION_STATUS_NOT_APPROVABLE', 'Transaction status is not approvable.');
            }

            if ($this->dependencyChecker->hasBlockingDependencies($transaction, $action, $module)) {
                $reasons = $this->dependencyChecker->blockingReasons($transaction, $action, $module);
                return TransactionPolicyResult::deny(
                    'TRANSACTION_HAS_DEPENDENCY',
                    'Transaction has related records and cannot be modified.',
                    $reasons
                );
            }

            $transactionDate ??= $this->getTransactionDate($transaction);
        }

        // 6) Date guard / period guard placeholder
        $dateCheck = $this->dateGuard->check($transactionDate, $action, $module);
        if ($dateCheck->denied() || $dateCheck->isWarning()) {
            return $dateCheck;
        }

        // 7) Allow
        return TransactionPolicyResult::allow();
    }

    private function resolvePermission(string $module, string $action): string|TransactionPolicyResult
    {
        $module = trim($module);
        $action = trim($action);

        if (! in_array($module, TransactionModule::all(), true)) {
            return TransactionPolicyResult::deny('UNKNOWN_TRANSACTION_MODULE', 'Unknown module.');
        }

        if (! in_array($action, TransactionAction::all(), true)) {
            return TransactionPolicyResult::deny('UNKNOWN_TRANSACTION_ACTION', 'Unknown action.');
        }

        return TransactionModule::permissionFor($module, $action);
    }

    private function getTransactionStatus(mixed $transaction): ?string
    {
        if ($transaction === null) {
            return null;
        }

        if (is_array($transaction)) {
            return isset($transaction['status']) ? (string) $transaction['status'] : null;
        }

        if (is_object($transaction)) {
            if (isset($transaction->status)) {
                return (string) $transaction->status;
            }
            if (method_exists($transaction, 'getAttribute')) {
                $value = $transaction->getAttribute('status');
                return $value !== null ? (string) $value : null;
            }
        }

        return null;
    }

    private function getTransactionDate(mixed $transaction): ?string
    {
        if ($transaction === null) {
            return null;
        }

        if (is_array($transaction)) {
            return isset($transaction['transaction_date']) ? (string) $transaction['transaction_date'] : null;
        }

        if (is_object($transaction)) {
            if (isset($transaction->transaction_date)) {
                return (string) $transaction->transaction_date;
            }
            if (method_exists($transaction, 'getAttribute')) {
                $value = $transaction->getAttribute('transaction_date');
                return $value !== null ? (string) $value : null;
            }
        }

        return null;
    }
}
