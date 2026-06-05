<?php

namespace App\Services\Accounting;

use App\Data\Reports\ProfitLossFilter;
use App\Data\Reports\TrialBalanceFilter;
use App\Exceptions\ApiException;
use App\Models\FiscalYear;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\FiscalYearClosing;
use App\Services\Audit\AuditLogService;
use App\Services\Reports\ProfitLossService;
use App\Services\Reports\TrialBalanceService;
use App\Services\Tenant\TenantContext;
use App\Support\AccountMapping\AccountMappingKey;
use App\Support\Api\ApiErrorCode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class FiscalYearClosingService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly FiscalYearService $fiscalYearService,
        private readonly ProfitLossService $profitLossService,
        private readonly TrialBalanceService $trialBalanceService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function previewClosing(int $fiscalYearId): array
    {
        $validation = $this->validateClosing($fiscalYearId);
        $company = $this->requireCompany();

        $fy = FiscalYear::query()->whereKey($fiscalYearId)->where('company_id', $company->id)->first();
        if (! $fy) {
            return [
                'valid' => false,
                'errors' => ['fiscal_year_id' => ['Fiscal year not found.']],
            ];
        }

        // Store a lightweight marker that preview has been performed.
        $meta = (array) ($fy->metadata ?? []);
        $meta['last_closing_preview_at'] = now()->toISOString();
        $fy->forceFill(['metadata' => $meta])->save();

        $net = $this->calculateRetainedEarnings($fiscalYearId);
        $mapping = $this->retainedEarningsMapping();

        $journalCount = DB::connection('tenant')->table('journal_entries')
            ->where('status', '=', 'posted')
            ->where('is_obsolete', '=', 0)
            ->whereDate('journal_date', '>=', $fy->start_date->toDateString())
            ->whereDate('journal_date', '<=', $fy->end_date->toDateString())
            ->count();

        $summary = [
            'fiscal_year' => [
                'id' => (int) $fy->id,
                'year' => (int) $fy->year,
                'start_date' => (string) $fy->start_date,
                'end_date' => (string) $fy->end_date,
                'status' => (string) $fy->status,
                'is_active' => (bool) $fy->is_active,
                'is_closed' => (bool) ($fy->is_closed ?? $fy->isClosed()),
            ],
            'net_profit_loss' => (float) $net,
            'retained_earnings_account' => [
                'mapping_key' => AccountMappingKey::CLOSING_RETAINED_EARNINGS,
                'account_id' => $mapping?->account_id,
            ],
            'journal_count' => (int) $journalCount,
            'warning_count' => count((array) ($validation['warnings'] ?? [])),
            'warnings' => (array) ($validation['warnings'] ?? []),
            'can_close' => (bool) ($validation['valid'] ?? false),
        ];

        $this->audit('fiscal_year.previewed', [
            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $company->id,
            'retained_earnings_amount' => (float) $net,
            'can_close' => (bool) ($validation['valid'] ?? false),
            'warnings' => $validation['warnings'] ?? [],
            'errors' => $validation['errors'] ?? [],
        ]);

        return [
            'valid' => (bool) ($validation['valid'] ?? false),
            'errors' => (array) ($validation['errors'] ?? []),
            'warnings' => (array) ($validation['warnings'] ?? []),
            'preview' => $summary,
        ];
    }

    public function generateClosingChecklist(int $fiscalYearId): array
    {
        $company = $this->requireCompany();

        $checks = [];
        $errors = [];
        $warnings = [];

        $fy = FiscalYear::query()->whereKey($fiscalYearId)->where('company_id', $company->id)->first();
        if (! $fy) {
            $errors['fiscal_year_id'][] = 'Fiscal year not found.';
            return [
                'can_close' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'checks' => [],
            ];
        }

        $checks[] = [
            'key' => 'fiscal_year_exists',
            'status' => 'passed',
            'message' => 'Fiscal year exists.',
        ];

        if ($fy->status !== 'open') {
            $errors['fiscal_year'][] = 'Fiscal year must be open to close.';
            $checks[] = [
                'key' => 'fiscal_year_open',
                'status' => 'failed',
                'message' => 'Fiscal year is not open.',
            ];
        } else {
            $checks[] = [
                'key' => 'fiscal_year_open',
                'status' => 'passed',
                'message' => 'Fiscal year is open.',
            ];
        }

        $mapping = $this->retainedEarningsMapping();
        if (! $mapping || ! $mapping->account_id) {
            $errors['retained_earnings'][] = 'Retained earnings account mapping is not configured.';
            $checks[] = [
                'key' => 'retained_earnings_configured',
                'status' => 'failed',
                'message' => 'Retained earnings account mapping is not configured.',
            ];
        } else {
            $checks[] = [
                'key' => 'retained_earnings_configured',
                'status' => 'passed',
                'message' => 'Retained earnings account mapping is configured.',
            ];
        }

        $tb = $this->trialBalanceService->getTrialBalance(new TrialBalanceFilter(
            startDate: $fy->start_date->toDateString(),
            endDate: $fy->end_date->toDateString(),
            departmentId: null,
            projectId: null,
            includeZeroBalance: false,
            includeInactiveAccounts: true,
            accountType: null,
            sortBy: 'account_code',
            sortDirection: 'asc',
        ));

        $tbBalanced = (bool) (($tb['totals']['is_balanced'] ?? false) && ($tb['valid'] ?? false));
        if (! $tbBalanced) {
            $errors['trial_balance'][] = 'Trial balance is not balanced.';
            $checks[] = [
                'key' => 'trial_balance_balanced',
                'status' => 'failed',
                'message' => 'Trial balance is not balanced.',
            ];
        } else {
            $checks[] = [
                'key' => 'trial_balance_balanced',
                'status' => 'passed',
                'message' => 'Trial balance is balanced.',
            ];
        }

        return [
            'can_close' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'checks' => $checks,
        ];
    }

    /**
     * @return array{valid:bool,errors:array,warnings:array}
     */
    public function validateClosing(int $fiscalYearId): array
    {
        $errors = [];
        $warnings = [];

        $company = $this->requireCompany();

        $fy = FiscalYear::query()->whereKey($fiscalYearId)->where('company_id', $company->id)->first();
        if (! $fy) {
            $errors['fiscal_year_id'][] = 'Fiscal year not found.';
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        if ($fy->status !== 'open') {
            $errors['fiscal_year'][] = 'Fiscal year must be open to close.';
        }

        if ((bool) ($fy->is_closed ?? false) || $fy->status === 'closed') {
            $errors['fiscal_year'][] = 'Fiscal year is already closed.';
        }

        if (FiscalYearClosing::query()->where('fiscal_year_id', $fy->id)->exists()) {
            $errors['closing'][] = 'Fiscal year closing record already exists.';
        }

        $mapping = $this->retainedEarningsMapping();
        if (! $mapping || ! $mapping->account_id) {
            $errors['retained_earnings'][] = 'Retained earnings account mapping is not configured.';
        }

        // Trial balance must be balanced within fiscal year range.
        $tb = $this->trialBalanceService->getTrialBalance(new TrialBalanceFilter(
            startDate: $fy->start_date->toDateString(),
            endDate: $fy->end_date->toDateString(),
            departmentId: null,
            projectId: null,
            includeZeroBalance: false,
            includeInactiveAccounts: true,
            accountType: null,
            sortBy: 'account_code',
            sortDirection: 'asc',
        ));

        if (! ($tb['valid'] ?? false)) {
            $errors['trial_balance'][] = 'Trial balance filter invalid.';
        } else {
            $balanced = (bool) ($tb['totals']['is_balanced'] ?? false);
            if (! $balanced) {
                $errors['trial_balance'][] = 'Trial balance is not balanced.';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    public function executeClosing(int $fiscalYearId, array $options = []): array
    {
        $company = $this->requireCompany();

        $this->audit('fiscal_year.close_attempted', [
            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $company->id,
        ]);

        $fyForPreviewCheck = FiscalYear::query()->whereKey($fiscalYearId)->where('company_id', $company->id)->first();
        $lastPreview = is_array($fyForPreviewCheck?->metadata ?? null) ? ($fyForPreviewCheck->metadata['last_closing_preview_at'] ?? null) : null;
        if (! $lastPreview) {
            return [
                'valid' => false,
                'errors' => ['closing_preview' => ['Closing preview must be performed before closing.']],
                'warnings' => [],
            ];
        }

        $validation = $this->validateClosing($fiscalYearId);
        if (! $validation['valid']) {
            $this->audit('fiscal_year.close_blocked', [
                'fiscal_year_id' => $fiscalYearId,
                'company_id' => $company->id,
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
            ], result: 'failed');

            return [
                'valid' => false,
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
            ];
        }

        $fy = FiscalYear::query()->whereKey($fiscalYearId)->where('company_id', $company->id)->firstOrFail();

        $notes = isset($options['closing_notes']) ? (string) $options['closing_notes'] : null;
        $userId = auth()->id();

        $retained = (float) $this->calculateRetainedEarnings($fiscalYearId);
        $mapping = $this->retainedEarningsMapping();

        try {
            DB::transaction(function () use ($fy, $userId, $notes, $retained, $mapping) {
                DB::connection('tenant')->transaction(function () use ($fy, $userId, $notes, $retained, $mapping) {
                    FiscalYearClosing::query()->create([
                        'fiscal_year_id' => (int) $fy->id,
                        'closed_by_user_id' => $userId ? (int) $userId : null,
                        'retained_earnings_account_id' => $mapping?->account_id,
                        'retained_earnings_amount' => $retained,
                        'closing_notes' => $notes,
                        'closed_at' => now(),
                        'status' => 'completed',
                        'metadata' => [
                            'company_id' => $fy->company_id,
                        ],
                    ]);
                });

                $this->fiscalYearService->closeFiscalYear($fy, $userId ? (int) $userId : null);

                $fy->forceFill([
                    'is_closed' => true,
                    'locked_until' => $fy->end_date?->toDateString(),
                    'reopened_at' => null,
                ])->save();
            });
        } catch (Throwable $e) {
            $this->audit('fiscal_year.closed', [
                'fiscal_year_id' => $fiscalYearId,
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ], result: 'failed');

            throw $e;
        }

        $this->audit('fiscal_year.closed', [
            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $company->id,
            'retained_earnings_amount' => $retained,
        ]);

        return [
            'valid' => true,
            'fiscal_year_id' => (int) $fy->id,
            'retained_earnings_amount' => $retained,
            'retained_earnings_account_id' => $mapping?->account_id,
            'closed_at' => now()->toISOString(),
        ];
    }

    public function reopenFiscalYear(int $fiscalYearId, array $options = []): array
    {
        $company = $this->requireCompany();

        $fy = FiscalYear::query()->whereKey($fiscalYearId)->where('company_id', $company->id)->first();
        if (! $fy) {
            return [
                'valid' => false,
                'errors' => ['fiscal_year_id' => ['Fiscal year not found.']],
            ];
        }

        if ($fy->status !== 'closed' && ! (bool) ($fy->is_closed ?? false)) {
            return [
                'valid' => false,
                'errors' => ['fiscal_year' => ['Fiscal year is not closed.']],
            ];
        }

        $reason = isset($options['reopen_reason']) ? trim((string) $options['reopen_reason']) : '';
        if ($reason === '') {
            return [
                'valid' => false,
                'errors' => ['reopen_reason' => ['Reopen reason is required.']],
            ];
        }

        $userId = auth()->id();

        DB::transaction(function () use ($fy, $userId, $reason) {
            $fy->forceFill([
                'status' => 'open',
                'is_closed' => false,
                'is_active' => true,
                'reopened_at' => now(),
                'locked_until' => null,
            ])->save();

            FiscalYearClosing::query()
                ->where('fiscal_year_id', $fy->id)
                ->update([
                    'reopened_by_user_id' => $userId ? (int) $userId : null,
                    'reopened_at' => now(),
                ]);
        });

        $this->audit('fiscal_year.reopened', [
            'fiscal_year_id' => (int) $fy->id,
            'company_id' => $company->id,
            'reopen_reason' => $reason,
        ]);

        return [
            'valid' => true,
            'fiscal_year_id' => (int) $fy->id,
            'reopened_at' => now()->toISOString(),
        ];
    }

    public function calculateRetainedEarnings(int $fiscalYearId): float
    {
        $company = $this->requireCompany();

        $fy = FiscalYear::query()->whereKey($fiscalYearId)->where('company_id', $company->id)->first();
        if (! $fy) {
            throw ApiException::make(ApiErrorCode::NOT_FOUND, 'Fiscal year not found.', 404);
        }

        $result = $this->profitLossService->getProfitLoss(new ProfitLossFilter(
            startDate: $fy->start_date->toDateString(),
            endDate: $fy->end_date->toDateString(),
            departmentId: null,
            projectId: null,
            includeZeroBalance: false,
            includeInactiveAccounts: true,
            groupBy: 'account_type',
        ));

        if (! ($result['valid'] ?? false)) {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Profit loss calculation failed.', 422, (array) ($result['errors'] ?? []));
        }

        return (float) ($result['totals']['net_profit_or_loss'] ?? 0);
    }

    public function isFiscalYearClosed(int $fiscalYearId): bool
    {
        $company = $this->requireCompany();
        $fy = FiscalYear::query()->whereKey($fiscalYearId)->where('company_id', $company->id)->first();
        if (! $fy) {
            return false;
        }

        return $fy->status === 'closed' || (bool) ($fy->is_closed ?? false);
    }

    public function assertFiscalYearOpenByDate(string $date): void
    {
        $company = $this->requireCompany();

        $fy = $this->fiscalYearService->fiscalYearForDate($company, Carbon::parse($date)->toDateString());
        if ($fy && ($fy->status === 'closed' || (bool) ($fy->is_closed ?? false))) {
            throw ApiException::make('FISCAL_YEAR_CLOSED', 'Fiscal year is closed. Transaction is read-only.', 422, [
                'transaction_date' => ['Transactions for this fiscal period are locked.'],
            ]);
        }
    }

    private function retainedEarningsMapping(): ?AccountMapping
    {
        return AccountMapping::query()
            ->where('mapping_key', AccountMappingKey::CLOSING_RETAINED_EARNINGS)
            ->first();
    }

    private function requireCompany()
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make(ApiErrorCode::COMPANY_NOT_FOUND, 'Company context not resolved.', 422);
        }
        return $company;
    }

    private function audit(string $event, array $metadata = [], string $result = 'success'): void
    {
        $data = [
            'event' => $event,
            'module' => 'accounting',
            'action' => $event,
            'message' => $event,
            'metadata' => $metadata,
        ];

        if ($result === 'failed') {
            $this->auditLogService->logFailed($data, tenant: true);
            return;
        }

        $this->auditLogService->logSuccess($data, tenant: true);
    }
}
