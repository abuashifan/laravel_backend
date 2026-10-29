<?php

namespace App\Shared\TransactionLifecycle;

use App\Modules\Settings\Services\CompanySettingService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Permission\PermissionService;
use App\Shared\Tenant\TenantContext;
use App\Shared\TransactionLifecycle\Contracts\TransactionDateGuard;
use App\Shared\TransactionLifecycle\Contracts\TransactionDependencyChecker;

class TransactionPolicyService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CompanySettingService $companySettingService,
        private readonly PermissionService $permissionService,
        private readonly TransactionDependencyChecker $dependencyChecker,
        private readonly TransactionDateGuard $dateGuard,
    ) {}

    /** Hanya draft yang boleh diedit/dihapus; approved/posted harus di-void dulu. */
    public const EDIT_MODE_LOCK = 'lock';

    /** Semua status (kecuali void) boleh diedit/dihapus selama tidak tertaut transaksi lain. */
    public const EDIT_MODE_OPEN = 'open';

    public const CHANGE_EDIT = 'edit';

    public const CHANGE_DELETE = 'delete';

    /**
     * Mode ubah transaksi perusahaan aktif: `lock` (bawaan) atau `open`.
     */
    public function editMode(): string
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return self::EDIT_MODE_LOCK;
        }

        return $this->normalizeEditMode($this->companySettingService->getOrCreateAccountingSetting($company)->transaction_edit_mode);
    }

    /**
     * Aturan bersama edit & hapus dokumen transaksi, menurut mode lock/open.
     *
     * Izin (permission) SENGAJA tidak dicek di sini -- rute tiap modul sudah
     * memakai izin granularnya sendiri (mis. `sales.invoices.edit`).
     *
     * @param  string  $change  self::CHANGE_EDIT | self::CHANGE_DELETE
     * @param  list<string>  $dependents  Transaksi lain yang tertaut (kalimat siap tampil). Tidak kosong = ditolak.
     * @param  list<string>  $lockChangeableStatuses  Status yang tetap boleh diubah di mode lock (bawaan: draft saja).
     */
    public function checkDocumentChange(
        string $change,
        string $module,
        string $status,
        array $dependents = [],
        ?string $transactionDate = null,
        array $lockChangeableStatuses = [TransactionStatus::DRAFT],
    ): TransactionPolicyResult {
        $verb = $change === self::CHANGE_DELETE ? 'dihapus' : 'diedit';

        if ($status === TransactionStatus::VOID) {
            return TransactionPolicyResult::deny('TRANSACTION_ALREADY_VOID', "Transaksi sudah void dan tidak bisa {$verb}.");
        }

        $company = $this->tenantContext->company();
        $settings = $company ? $this->companySettingService->getOrCreateAccountingSetting($company) : null;

        if ($change === self::CHANGE_EDIT && ! (bool) ($settings?->allow_edit_transactions ?? true)) {
            return TransactionPolicyResult::deny('COMPANY_SETTING_EDIT_DISABLED', 'Pengaturan perusahaan tidak mengizinkan transaksi diedit.');
        }

        $changeableInLock = in_array($status, $lockChangeableStatuses, true);
        $mode = $this->normalizeEditMode($settings?->transaction_edit_mode);

        if ($mode === self::EDIT_MODE_LOCK && ! $changeableInLock) {
            // Tahun fiskal/periode tertutup lebih mendasar daripada mode lock:
            // alasannya yang ditampilkan kalau keduanya berlaku.
            $dateCheck = $this->dateGuard->check($transactionDate, TransactionAction::EDIT, $module);
            if ($dateCheck->denied()) {
                return $dateCheck;
            }

            return $this->lockedResult($verb, $status);
        }

        // Menghapus transaksi yang sudah berefek = membatalkan efeknya (void).
        if ($change === self::CHANGE_DELETE && ! $changeableInLock && ! (bool) ($settings?->allow_void_transactions ?? true)) {
            return TransactionPolicyResult::deny('COMPANY_SETTING_VOID_DISABLED', 'Pengaturan perusahaan tidak mengizinkan void, jadi transaksi yang sudah diposting tidak bisa dihapus.');
        }

        if ($dependents !== []) {
            return TransactionPolicyResult::deny(
                'TRANSACTION_HAS_DEPENDENCY',
                "Transaksi ini tidak bisa {$verb} karena masih tertaut dengan transaksi lain: ".implode('; ', $dependents).'.',
                ['dependents' => $dependents],
            );
        }

        // Efek transaksi yang sudah dibukukan ikut dibatalkan, jadi periodenya
        // harus masih terbuka -- sama dengan syarat void.
        if (! $changeableInLock) {
            $dateCheck = $this->dateGuard->check($transactionDate, TransactionAction::VOID, $module);
            if ($dateCheck->denied()) {
                return $dateCheck;
            }
        }

        return TransactionPolicyResult::allow();
    }

    /**
     * Sama dengan `checkDocumentChange()`, tapi langsung melempar galat API.
     *
     * @param  list<string>  $dependents
     * @param  list<string>  $lockChangeableStatuses
     */
    public function assertDocumentChange(
        string $change,
        string $module,
        string $status,
        array $dependents = [],
        ?string $transactionDate = null,
        array $lockChangeableStatuses = [TransactionStatus::DRAFT],
    ): void {
        $result = $this->checkDocumentChange($change, $module, $status, $dependents, $transactionDate, $lockChangeableStatuses);

        if ($result->denied()) {
            $arr = $result->toArray();

            throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']);
        }
    }

    private function lockedResult(string $verb, string $status): TransactionPolicyResult
    {
        return TransactionPolicyResult::deny(
            'TRANSACTION_LOCKED',
            "Mode lock aktif: transaksi berstatus '{$status}' tidak bisa {$verb}. Void transaksinya dulu, lalu buat ulang.",
            [],
            ['transaction_edit_mode' => self::EDIT_MODE_LOCK, 'status' => $status],
        );
    }

    private function normalizeEditMode(?string $mode): string
    {
        return $mode === self::EDIT_MODE_OPEN ? self::EDIT_MODE_OPEN : self::EDIT_MODE_LOCK;
    }

    public function canView(string $module, mixed $transaction = null, ?string $feature = null): TransactionPolicyResult
    {
        return $this->check($module, TransactionAction::VIEW, $transaction, null, $feature);
    }

    public function canCreate(string $module, ?string $transactionDate = null, ?string $feature = null): TransactionPolicyResult
    {
        return $this->check($module, TransactionAction::CREATE, null, $transactionDate, $feature);
    }

    public function canEdit(string $module, mixed $transaction, ?string $feature = null): TransactionPolicyResult
    {
        return $this->check($module, TransactionAction::EDIT, $transaction, null, $feature);
    }

    public function canVoid(string $module, mixed $transaction, ?string $feature = null): TransactionPolicyResult
    {
        return $this->check($module, TransactionAction::VOID, $transaction, null, $feature);
    }

    public function canApprove(string $module, mixed $transaction, ?string $feature = null): TransactionPolicyResult
    {
        return $this->check($module, TransactionAction::APPROVE, $transaction, null, $feature);
    }

    public function canPost(string $module, mixed $transaction, ?string $feature = null): TransactionPolicyResult
    {
        return $this->check($module, TransactionAction::POST, $transaction, null, $feature);
    }

    public function check(
        string $module,
        string $action,
        mixed $transaction = null,
        ?string $transactionDate = null,
        ?string $feature = null
    ): TransactionPolicyResult {
        // 1) Validate module/action
        $permissionResult = $this->resolvePermission($module, $action, $feature);
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
        $editMode = $this->normalizeEditMode($settings?->transaction_edit_mode);
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
                // Mode lock menggantikan `allow_edit_posted_transactions`: hanya
                // draft yang boleh diedit; sisanya lewat void.
                if ($editMode === self::EDIT_MODE_LOCK && $status !== TransactionStatus::DRAFT) {
                    $dateCheck = $this->dateGuard->check($transactionDate ?? $this->getTransactionDate($transaction), $action, $module);
                    if ($dateCheck->denied()) {
                        return $dateCheck;
                    }

                    return $this->lockedResult('diedit', $status);
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

    private function resolvePermission(string $module, string $action, ?string $feature): string|TransactionPolicyResult
    {
        $module = trim($module);
        $action = trim($action);

        if (! in_array($module, TransactionModule::all(), true)) {
            return TransactionPolicyResult::deny('UNKNOWN_TRANSACTION_MODULE', 'Unknown module.');
        }

        if (! in_array($action, TransactionAction::all(), true)) {
            return TransactionPolicyResult::deny('UNKNOWN_TRANSACTION_ACTION', 'Unknown action.');
        }

        try {
            return TransactionModule::permissionFor($module, $action, $feature);
        } catch (\InvalidArgumentException) {
            return TransactionPolicyResult::deny('TRANSACTION_FEATURE_REQUIRED', 'A specific transaction feature is required.');
        }
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
