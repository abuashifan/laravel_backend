<?php

namespace App\Services\Transactions;

use App\Contracts\Transactions\TransactionDateGuard;
use App\Models\Company;
use App\Services\Accounting\AnnualClosingGateService;
use App\Services\Accounting\FiscalYearService;
use App\Services\Accounting\PeriodLockService;
use App\Services\Settings\CompanySettingService;
use App\Services\Tenant\TenantContext;
use App\Support\Transaction\TransactionPolicyResult;
use Carbon\Carbon;
use Throwable;

class TransactionDateGuardService implements TransactionDateGuard
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CompanySettingService $companySettingService,
        private readonly FiscalYearService $fiscalYearService,
        private readonly PeriodLockService $periodLockService,
        private readonly AnnualClosingGateService $annualClosingGateService,
    ) {
    }

    public function check(?string $transactionDate, string $action, string $module): TransactionPolicyResult
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return TransactionPolicyResult::allow();
        }

        return $this->checkForCompany($company, $transactionDate, $action, $module);
    }

    public function checkForCompany(
        Company $company,
        ?string $transactionDate,
        string $action,
        string $module
    ): TransactionPolicyResult {
        if ($transactionDate === null) {
            return TransactionPolicyResult::allow();
        }

        try {
            $date = Carbon::parse($transactionDate)->toDateString();
        } catch (Throwable $e) {
            return TransactionPolicyResult::deny('TRANSACTION_DATE_INVALID', 'Transaction date is invalid.');
        }

        $settings = $this->companySettingService->getOrCreateAccountingSetting($company);

        // read-only checks first
        if ($this->periodLockService->isDateReadOnly($company, $date)) {
            $reason = $this->periodLockService->blockingReasonForDate($company, $date);
            if ($reason === 'FISCAL_YEAR_CLOSED') {
                return TransactionPolicyResult::deny('FISCAL_YEAR_CLOSED', 'Fiscal year is closed. Transaction is read-only.', [
                    'transaction_date' => ['Transactions for this fiscal period are locked.'],
                ]);
            }

            return TransactionPolicyResult::deny('TRANSACTION_PERIOD_LOCKED', 'Transactions for this fiscal period are locked.', [
                'transaction_date' => ['Transactions for this fiscal period are locked.'],
            ]);
        }

        $activeFiscalYear = $this->fiscalYearService->getOrCreateActiveFiscalYear($company);

        // block entering next fiscal year if previous not closed (annual gate)
        $blockingFy = $this->annualClosingGateService->blockingFiscalYear($company, $date);
        if ($blockingFy) {
            return TransactionPolicyResult::deny(
                'PREVIOUS_FISCAL_YEAR_NOT_CLOSED',
                'Previous fiscal year must be closed before entering transactions in the next fiscal year.',
                [],
                ['blocking_fiscal_year' => $blockingFy->year]
            );
        }

        // block outside active fiscal year (if enabled)
        if ($settings->block_outside_current_fiscal_year) {
            if (! $activeFiscalYear->containsDate($date)) {
                return TransactionPolicyResult::deny(
                    'TRANSACTION_DATE_OUTSIDE_ACTIVE_FISCAL_YEAR',
                    'Transaction date is outside the active fiscal year.',
                    [],
                    ['active_fiscal_year' => $activeFiscalYear->year]
                );
            }
        }

        $today = Carbon::today();
        $tx = Carbon::parse($date);

        // backdated rules
        if (! $settings->allow_backdated_transactions && $tx->lessThan($today)) {
            return TransactionPolicyResult::deny('BACKDATED_TRANSACTION_NOT_ALLOWED', 'Backdated transaction is not allowed.');
        }

        if ($settings->max_backdate_days !== null && $tx->lessThan($today->copy()->subDays((int) $settings->max_backdate_days))) {
            return TransactionPolicyResult::deny('BACKDATED_TRANSACTION_TOO_FAR', 'Backdated transaction is too far.');
        }

        // future rules
        if (! $settings->allow_future_transactions && $tx->greaterThan($today)) {
            return TransactionPolicyResult::deny('FUTURE_TRANSACTION_NOT_ALLOWED', 'Future transaction is not allowed.');
        }

        if ($settings->max_future_days !== null && $tx->greaterThan($today->copy()->addDays((int) $settings->max_future_days))) {
            return TransactionPolicyResult::deny('FUTURE_TRANSACTION_TOO_FAR', 'Future transaction is too far.');
        }

        // warnings
        if ($settings->date_warning_enabled) {
            if ($tx->greaterThan($today)) {
                return TransactionPolicyResult::warning('FUTURE_TRANSACTION_DATE_WARNING', 'Transaction date is in the future.');
            }

            if ($tx->format('Y-m') !== $today->format('Y-m') && $activeFiscalYear->containsDate($date)) {
                return TransactionPolicyResult::warning('DIFFERENT_PERIOD_DATE_WARNING', 'Transaction date is in a different accounting period.');
            }
        }

        return TransactionPolicyResult::allow();
    }
}
