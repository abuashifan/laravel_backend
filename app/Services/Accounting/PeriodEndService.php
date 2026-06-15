<?php

namespace App\Services\Accounting;

use App\Exceptions\ApiException;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\FixedAssetDepreciationRun;
use App\Models\Tenant\FixedAssetDepreciationSchedule;
use App\Models\Tenant\FixedAssetTransaction;
use App\Models\Tenant\PeriodEndRun;
use App\Models\Tenant\PeriodEndRunRoutine;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\FixedAssets\FixedAssetService;
use App\Services\Permissions\PermissionService;
use App\Services\Tenant\TenantContext;
use App\Services\Transactions\TransactionVoidEffectService;
use App\Support\DocumentNumbering\DocumentType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PeriodEndService
{
    private const ROUTINE_FIXED_ASSET_DEPRECIATION = 'fixed_asset_depreciation';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly AuditLogService $auditLogService,
        private readonly PermissionService $permissionService,
        private readonly FixedAssetService $fixedAssetService,
        private readonly TransactionVoidEffectService $voidEffectService,
    ) {
    }

    public function status(string $period): array
    {
        $checklist = $this->buildChecklist($period);
        $run = PeriodEndRun::query()
            ->with('routines')
            ->where('period', $period)
            ->latest('id')
            ->first();

        return [
            'period' => $period,
            'accounting_period' => $this->serializeAccountingPeriod($checklist['accounting_period'] ?? null),
            'status' => $run?->status ?? 'not_started',
            'can_run' => $checklist['can_run'],
            'can_reopen' => $run?->status === 'completed',
            'run' => $run,
            'checklist' => $this->serializeChecklist($checklist),
        ];
    }

    public function checklist(string $period): array
    {
        $checklist = $this->buildChecklist($period);
        $this->audit('period_end.checklist', 'Period-end checklist generated.', [
            'period' => $period,
            'blocking_error_count' => count($checklist['blocking_errors']),
            'warning_count' => count($checklist['warnings']),
        ]);

        return $this->serializeChecklist($checklist);
    }

    public function run(string $period): PeriodEndRun
    {
        if ($this->permissionService->cannot('fixed_assets.depreciate')) {
            throw ApiException::make('FORBIDDEN', 'Missing permission: fixed_assets.depreciate.', 403);
        }

        $this->audit('period_end.run_attempted', 'Period-end run attempted.', ['period' => $period]);

        $existingCompleted = PeriodEndRun::query()
            ->with('routines')
            ->where('period', $period)
            ->where('status', 'completed')
            ->latest('id')
            ->first();

        if ($existingCompleted) {
            $this->audit('period_end.run_idempotent', 'Period-end run already completed.', [
                'period' => $period,
                'period_end_run_id' => $existingCompleted->id,
            ]);

            return $existingCompleted;
        }

        $checklist = $this->buildChecklist($period);
        if ($checklist['blocking_errors'] !== []) {
            $this->audit('period_end.run_rejected', 'Period-end run rejected by checklist.', [
                'period' => $period,
                'blocking_errors' => $checklist['blocking_errors'],
            ]);

            throw ApiException::make('PERIOD_END_CHECKLIST_BLOCKED', 'Period-end checklist has blocking errors.', 422, [
                'blocking_errors' => $checklist['blocking_errors'],
            ]);
        }

        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        $accountingPeriod = $checklist['accounting_period'];
        $parts = $this->parsePeriod($period);
        $run = null;

        try {
            $run = DB::connection('tenant')->transaction(function () use ($period, $parts, $company, $accountingPeriod, $checklist, &$run) {
                $run = PeriodEndRun::query()
                    ->where('period', $period)
                    ->whereIn('status', ['draft', 'failed', 'reopened'])
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if (! $run) {
                    $run = PeriodEndRun::query()->create([
                        'run_number' => $this->documentNumberService->generate($company, DocumentType::PERIOD_END, $parts['start_date']),
                        'accounting_period_id' => $accountingPeriod->id,
                        'period_year' => $parts['year'],
                        'period_month' => $parts['month'],
                        'period' => $period,
                        'status' => 'draft',
                        'created_by' => auth()->id(),
                    ]);
                }

                $run->forceFill([
                    'accounting_period_id' => $accountingPeriod->id,
                    'status' => 'running',
                    'checklist_snapshot' => $this->publicChecklist($checklist),
                    'started_at' => now(),
                    'failed_at' => null,
                    'metadata' => array_merge((array) $run->metadata, [
                        'routine_registry' => [self::ROUTINE_FIXED_ASSET_DEPRECIATION],
                    ]),
                ])->save();

                $routine = $this->routineForRun($run, $period);
                $this->runFixedAssetRoutine($routine, $parts['year'], $parts['month']);

                $run->forceFill([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'completed_by' => auth()->id(),
                    'metadata' => array_merge((array) $run->metadata, [
                        'completed_routines' => [self::ROUTINE_FIXED_ASSET_DEPRECIATION],
                    ]),
                ])->save();

                $this->closeAccountingPeriod($accountingPeriod, $run);

                return $run->refresh()->load('routines');
            });

            $this->audit('period_end.run_completed', 'Period-end run completed.', [
                'period' => $period,
                'period_end_run_id' => $run->id,
            ]);

            return $run;
        } catch (Throwable $e) {
            $this->markRunFailed($run, $company, $accountingPeriod, $parts, $period, $e->getMessage());

            $this->audit('period_end.run_failed', 'Period-end run failed.', [
                'period' => $period,
                'period_end_run_id' => $run?->id,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function reopen(string $period, string $reason): PeriodEndRun
    {
        $reason = $this->voidEffectService->requireReason($reason);
        $this->audit('period_end.reopen_attempted', 'Period-end reopen attempted.', [
            'period' => $period,
            'reason' => $reason,
        ]);

        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        $accountingPeriod = $this->resolveAccountingPeriod($period);
        if (! $accountingPeriod) {
            throw ApiException::make('ACCOUNTING_PERIOD_NOT_FOUND', 'Accounting period is required before period-end reopen.', 422);
        }

        $fiscalYear = $accountingPeriod->fiscalYear;
        $parts = $this->parsePeriod($period);
        $this->assertPeriodCanReopen($company->id, $period, $parts['start_date'], $accountingPeriod, $fiscalYear);

        $run = PeriodEndRun::query()
            ->with('routines')
            ->where('period', $period)
            ->where('status', 'completed')
            ->latest('id')
            ->first();

        if (! $run) {
            $this->audit('period_end.reopen_rejected', 'Period-end reopen rejected because completed run was not found.', ['period' => $period]);
            throw ApiException::make('PERIOD_END_RUN_NOT_COMPLETED', 'Completed period-end run was not found.', 422);
        }

        $reopened = DB::connection('tenant')->transaction(function () use ($run, $period, $reason, $accountingPeriod) {
            $run = PeriodEndRun::query()->with('routines')->lockForUpdate()->findOrFail($run->id);

            foreach ($run->routines as $routine) {
                if ($routine->routine_key !== self::ROUTINE_FIXED_ASSET_DEPRECIATION) {
                    continue;
                }

                $this->reverseFixedAssetRoutine($routine, $period, $reason);
            }

            $run->forceFill([
                'status' => 'reopened',
                'reopened_at' => now(),
                'reopened_by' => auth()->id(),
                'metadata' => array_merge((array) $run->metadata, ['reopen_reason' => $reason]),
            ])->save();

            $this->openAccountingPeriod($accountingPeriod, $run, $reason);

            return $run->refresh()->load('routines');
        });

        $this->audit('period_end.reopen_completed', 'Period-end reopened.', [
            'period' => $period,
            'period_end_run_id' => $reopened->id,
            'reason' => $reason,
        ]);

        return $reopened;
    }

    private function buildChecklist(string $period): array
    {
        $parts = $this->parsePeriod($period);
        $blocking = [];
        $warnings = [];
        $items = [];
        $accountingPeriod = $this->resolveAccountingPeriod($period);

        if (! $accountingPeriod) {
            $blocking[] = ['code' => 'ACCOUNTING_PERIOD_NOT_FOUND', 'message' => 'Accounting period must exist before period-end can run.'];
            $items[] = ['key' => 'accounting_period_exists', 'status' => 'blocking'];
        } else {
            $items[] = ['key' => 'accounting_period_exists', 'status' => 'passed'];

            if ((string) $accountingPeriod->status === 'closed') {
                $completedRun = PeriodEndRun::query()->where('period', $period)->where('status', 'completed')->exists();
                if (! $completedRun) {
                    $blocking[] = ['code' => 'ACCOUNTING_PERIOD_CLOSED', 'message' => 'Accounting period is already closed.'];
                }
            }

            $fiscalYear = $accountingPeriod->fiscalYear;
            if (! $fiscalYear) {
                $blocking[] = ['code' => 'FISCAL_YEAR_NOT_FOUND', 'message' => 'Fiscal year must exist for selected period.'];
                $items[] = ['key' => 'fiscal_year_open', 'status' => 'blocking'];
            } elseif ((string) $fiscalYear->status === 'closed') {
                $blocking[] = ['code' => 'FISCAL_YEAR_CLOSED', 'message' => 'Fiscal year is closed.'];
                $items[] = ['key' => 'fiscal_year_open', 'status' => 'blocking'];
            } else {
                $items[] = ['key' => 'fiscal_year_open', 'status' => 'passed'];
            }

            if ($this->isPeriodLocked($fiscalYear ?? null, $parts['start_date'])) {
                $blocking[] = ['code' => 'PERIOD_LOCKED', 'message' => 'Selected period is locked.'];
                $items[] = ['key' => 'period_not_locked', 'status' => 'blocking'];
            } else {
                $items[] = ['key' => 'period_not_locked', 'status' => 'passed'];
            }
        }

        $setupStatus = $this->setupStatus();
        if ($setupStatus !== null && $setupStatus !== 'finalized') {
            $blocking[] = ['code' => 'SETUP_NOT_FINALIZED', 'message' => 'Company setup must be finalized before period-end can run.'];
            $items[] = ['key' => 'setup_finalized', 'status' => 'blocking', 'status_value' => $setupStatus];
        } else {
            $items[] = ['key' => 'setup_finalized', 'status' => $setupStatus === null ? 'not_applicable' : 'passed'];
        }

        foreach ($this->requiredFixedAssetMappingErrors($period) as $error) {
            $blocking[] = $error;
        }
        $items[] = [
            'key' => 'fixed_asset_account_mappings',
            'status' => collect($blocking)->contains(fn (array $error): bool => str_starts_with((string) $error['code'], 'FIXED_ASSET_MAPPING_')) ? 'blocking' : 'passed',
        ];

        $preview = $this->fixedAssetRoutinePreview($period);
        $items[] = [
            'key' => 'fixed_asset_depreciation_preview',
            'status' => $preview['eligible_line_count'] > 0 ? 'ready' : 'zero_lines',
            'amount' => $preview['total_amount'],
        ];

        $completedRoutine = PeriodEndRunRoutine::query()
            ->where('period', $period)
            ->where('routine_key', self::ROUTINE_FIXED_ASSET_DEPRECIATION)
            ->whereIn('status', ['completed', 'skipped'])
            ->exists();

        if ($completedRoutine) {
            $warnings[] = ['code' => 'ROUTINE_ALREADY_COMPLETED', 'message' => 'Fixed asset depreciation routine is already completed for this period.'];
            $items[] = ['key' => 'no_duplicate_completed_routine', 'status' => 'idempotent'];
        } else {
            $items[] = ['key' => 'no_duplicate_completed_routine', 'status' => 'passed'];
        }

        if (PeriodEndRun::query()->where('period', $period)->where('status', 'failed')->exists()) {
            $warnings[] = ['code' => 'FAILED_RUN_EXISTS', 'message' => 'A failed period-end run exists; retry will reuse completed-safe routines only.'];
            $items[] = ['key' => 'no_failed_unreconciled_run', 'status' => 'warning'];
        } else {
            $items[] = ['key' => 'no_failed_unreconciled_run', 'status' => 'passed'];
        }

        return [
            'period' => $period,
            'period_year' => $parts['year'],
            'period_month' => $parts['month'],
            'status' => $blocking === [] ? ($completedRoutine ? 'completed' : 'ready') : 'blocked',
            'can_run' => $blocking === [] && ! $completedRoutine,
            'blocking_errors' => $blocking,
            'warnings' => $warnings,
            'items' => $items,
            'routines' => [
                self::ROUTINE_FIXED_ASSET_DEPRECIATION => $preview,
            ],
            'accounting_period' => $accountingPeriod,
        ];
    }

    private function runFixedAssetRoutine(PeriodEndRunRoutine $routine, int $year, int $month): void
    {
        if (in_array((string) $routine->status, ['completed', 'skipped'], true)) {
            return;
        }

        $period = sprintf('%04d-%02d', $year, $month);
        $eligibleCount = FixedAssetDepreciationSchedule::query()
            ->where('period', $period)
            ->where('status', 'scheduled')
            ->count();

        if ($eligibleCount === 0) {
            $routine->forceFill([
                'status' => 'skipped',
                'completed_at' => now(),
                'metadata' => array_merge((array) $routine->metadata, [
                    'eligible_line_count' => 0,
                    'total_amount' => 0,
                ]),
            ])->save();

            return;
        }

        try {
            $routine->forceFill([
                'status' => 'running',
                'started_at' => now(),
                'failed_at' => null,
                'error_message' => null,
            ])->save();

            $depreciationRun = $this->fixedAssetService->postDepreciationPeriod($year, $month);
            $routine->loadMissing('run');
            if ($depreciationRun->journal_entry_id) {
                $depreciationRun->journalEntry()->update([
                    'source_type' => 'period_end',
                    'source_id' => $routine->id,
                    'source_number' => $routine->run?->run_number,
                    'source_module' => 'period_end',
                ]);
            }

            $routine->forceFill([
                'status' => 'completed',
                'journal_entry_id' => $depreciationRun->journal_entry_id,
                'completed_at' => now(),
                'metadata' => array_merge((array) $routine->metadata, [
                    'fixed_asset_depreciation_run_id' => $depreciationRun->id,
                    'line_count' => $depreciationRun->lines->count(),
                    'total_amount' => round((float) $depreciationRun->lines->sum('depreciation_amount'), 2),
                ]),
            ])->save();
        } catch (Throwable $e) {
            $routine->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }

    private function reverseFixedAssetRoutine(PeriodEndRunRoutine $routine, string $period, string $reason): void
    {
        if ($routine->status === 'reversed') {
            return;
        }

        if ($routine->status === 'skipped') {
            $routine->forceFill([
                'status' => 'reversed',
                'metadata' => array_merge((array) $routine->metadata, ['reversal_reason' => $reason]),
            ])->save();
            return;
        }

        $metadata = (array) $routine->metadata;
        $depreciationRunId = $metadata['fixed_asset_depreciation_run_id'] ?? null;
        $depreciationRun = $depreciationRunId
            ? FixedAssetDepreciationRun::query()->with('lines.asset', 'lines.schedule')->lockForUpdate()->find((int) $depreciationRunId)
            : FixedAssetDepreciationRun::query()->with('lines.asset', 'lines.schedule')->where('period', $period)->where('status', 'posted')->latest('id')->first();

        if (! $depreciationRun) {
            $routine->forceFill([
                'status' => 'reversed',
                'metadata' => array_merge($metadata, ['reversal_reason' => $reason, 'reversal_note' => 'Depreciation run not found.']),
            ])->save();
            return;
        }

        $this->voidEffectService->voidJournalById($depreciationRun->journal_entry_id ?: $routine->journal_entry_id, $reason);

        foreach ($depreciationRun->lines as $line) {
            $schedule = $line->schedule;
            if ($schedule) {
                $schedule->forceFill([
                    'status' => 'scheduled',
                    'journal_entry_id' => null,
                ])->save();
            }

            $asset = $line->asset;
            if ($asset) {
                $asset->forceFill([
                    'accumulated_depreciation' => max(0, round((float) $asset->accumulated_depreciation - (float) $line->depreciation_amount, 2)),
                    'net_book_value' => round((float) $asset->net_book_value + (float) $line->depreciation_amount, 2),
                ])->save();
            }
        }

        FixedAssetTransaction::query()
            ->where('source_type', 'fixed_asset_depreciation')
            ->where('source_id', $depreciationRun->id)
            ->delete();

        $depreciationRun->forceFill([
            'status' => 'voided',
            'metadata' => array_merge((array) $depreciationRun->metadata, ['void_reason' => $reason]),
        ])->save();

        $routine->forceFill([
            'status' => 'reversed',
            'metadata' => array_merge($metadata, [
                'reversal_reason' => $reason,
                'fixed_asset_depreciation_run_id' => $depreciationRun->id,
                'voided_journal_id' => $depreciationRun->journal_entry_id,
            ]),
        ])->save();
    }

    private function routineForRun(PeriodEndRun $run, string $period): PeriodEndRunRoutine
    {
        return PeriodEndRunRoutine::query()->firstOrCreate(
            [
                'period_end_run_id' => $run->id,
                'routine_key' => self::ROUTINE_FIXED_ASSET_DEPRECIATION,
            ],
            [
                'period' => $period,
                'routine_name' => 'Fixed Asset Depreciation/Amortization',
                'status' => 'pending',
            ]
        );
    }

    private function fixedAssetRoutinePreview(string $period): array
    {
        $scheduled = FixedAssetDepreciationSchedule::query()
            ->where('period', $period)
            ->where('status', 'scheduled');

        $posted = FixedAssetDepreciationSchedule::query()
            ->where('period', $period)
            ->where('status', 'posted');

        return [
            'routine_key' => self::ROUTINE_FIXED_ASSET_DEPRECIATION,
            'routine_name' => 'Fixed Asset Depreciation/Amortization',
            'eligible_line_count' => (clone $scheduled)->count(),
            'posted_line_count' => (clone $posted)->count(),
            'total_amount' => round((float) (clone $scheduled)->sum('depreciation_amount'), 2),
        ];
    }

    private function requiredFixedAssetMappingErrors(string $period): array
    {
        $errors = [];
        $required = collect((array) config('account_mappings.mappings', []))
            ->filter(fn (array $mapping): bool => ($mapping['module'] ?? null) === 'fixed_assets' && (bool) ($mapping['required'] ?? false));

        $hasScheduledAmortization = FixedAssetDepreciationSchedule::query()
            ->where('period', $period)
            ->where('status', 'scheduled')
            ->whereHas('asset', fn ($query) => $query->where('depreciation_type', 'amortization'))
            ->exists();

        if ($hasScheduledAmortization) {
            foreach (['fixed_assets.accumulated_amortization', 'fixed_assets.amortization_expense'] as $conditionalKey) {
                if (isset($required[$conditionalKey])) {
                    continue;
                }

                $definition = (array) config('account_mappings.mappings.'.$conditionalKey, []);
                if ($definition !== []) {
                    $required[$conditionalKey] = $definition;
                }
            }
        }

        foreach ($required as $key => $definition) {
            $mapping = AccountMapping::query()
                ->where('mapping_key', (string) $key)
                ->where('is_active', true)
                ->first();

            if (! $mapping?->account_id) {
                $errors[] = [
                    'code' => 'FIXED_ASSET_MAPPING_MISSING',
                    'message' => "Required fixed asset account mapping [{$key}] is missing.",
                    'mapping_key' => (string) $key,
                    'label' => $definition['label'] ?? (string) $key,
                ];
            }
        }

        return $errors;
    }

    private function closeAccountingPeriod(AccountingPeriod $period, PeriodEndRun $run): void
    {
        $metadata = array_merge((array) $period->metadata, [
            'period_end_run_id' => $run->id,
            'period_end_run_number' => $run->run_number,
            'period_end_completed_at' => now()->toIso8601String(),
        ]);

        $period->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => auth()->id(),
            'metadata' => $metadata,
        ])->save();
    }

    private function openAccountingPeriod(AccountingPeriod $period, PeriodEndRun $run, string $reason): void
    {
        $metadata = array_merge((array) $period->metadata, [
            'period_end_reopened_run_id' => $run->id,
            'period_end_reopened_at' => now()->toIso8601String(),
            'period_end_reopen_reason' => $reason,
        ]);

        $period->forceFill([
            'status' => 'open',
            'closed_at' => null,
            'closed_by' => null,
            'metadata' => $metadata,
        ])->save();
    }

    private function assertPeriodCanReopen(int $companyId, string $period, string $periodStart, AccountingPeriod $accountingPeriod, ?FiscalYear $fiscalYear): void
    {
        if ($fiscalYear && (string) $fiscalYear->status === 'closed') {
            $this->audit('period_end.reopen_rejected', 'Period-end reopen rejected because fiscal year is closed.', ['period' => $period]);
            throw ApiException::make('FISCAL_YEAR_CLOSED', 'Fiscal year is closed.', 422);
        }

        if ($this->isPeriodLocked($fiscalYear, $periodStart)) {
            $this->audit('period_end.reopen_rejected', 'Period-end reopen rejected because period is locked.', ['period' => $period]);
            throw ApiException::make('PERIOD_LOCKED', 'Selected period is locked.', 422);
        }

        $laterCompletedRun = PeriodEndRun::query()
            ->where('period', '>', $period)
            ->where('status', 'completed')
            ->exists();

        $laterClosedPeriod = AccountingPeriod::query()
            ->where('company_id', $companyId)
            ->where('start_date', '>', $accountingPeriod->end_date)
            ->where('status', 'closed')
            ->exists();

        if ($laterCompletedRun || $laterClosedPeriod) {
            $this->audit('period_end.reopen_rejected', 'Period-end reopen rejected because a later period is already closed.', ['period' => $period]);
            throw ApiException::make('LATER_PERIOD_ALREADY_CLOSED', 'Cannot reopen while a later period is already completed or closed.', 422);
        }
    }

    private function markRunFailed(?PeriodEndRun $run, Company $company, AccountingPeriod $accountingPeriod, array $parts, string $period, string $message): void
    {
        $payload = [
            'accounting_period_id' => $accountingPeriod->id,
            'period_year' => $parts['year'],
            'period_month' => $parts['month'],
            'period' => $period,
            'status' => 'failed',
            'failed_at' => now(),
            'created_by' => auth()->id(),
            'metadata' => ['error_message' => $message],
        ];

        if ($run instanceof PeriodEndRun && $run->id && PeriodEndRun::query()->whereKey($run->id)->exists()) {
            $run->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'metadata' => array_merge((array) $run->metadata, ['error_message' => $message]),
            ])->save();
            return;
        }

        PeriodEndRun::query()->create(array_merge($payload, [
            'run_number' => $this->documentNumberService->generate($company, DocumentType::PERIOD_END, $parts['start_date']),
        ]));
    }

    private function resolveAccountingPeriod(string $period): ?AccountingPeriod
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return null;
        }

        $parts = $this->parsePeriod($period);

        return AccountingPeriod::query()
            ->with('fiscalYear')
            ->where('company_id', $company->id)
            ->where('period_year', $parts['year'])
            ->where('period_month', $parts['month'])
            ->first();
    }

    private function setupStatus(): ?string
    {
        $company = $this->tenantContext->company();
        if (! $company || ! Schema::hasTable('company_setup_states')) {
            return null;
        }

        $state = DB::table('company_setup_states')
            ->where('company_id', $company->id)
            ->first();

        return $state ? (string) $state->status : null;
    }

    private function parsePeriod(string $period): array
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw ApiException::make('INVALID_PERIOD', 'Period must use YYYY-MM format.', 422, [
                'period' => ['Period must use YYYY-MM format.'],
            ]);
        }

        $date = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();

        return [
            'year' => (int) $date->year,
            'month' => (int) $date->month,
            'start_date' => $date->toDateString(),
            'end_date' => $date->copy()->endOfMonth()->toDateString(),
        ];
    }

    private function isPeriodLocked(?FiscalYear $fiscalYear, string $periodStart): bool
    {
        if (! $fiscalYear?->locked_until) {
            return false;
        }

        return Carbon::parse($periodStart)->lte(Carbon::parse($fiscalYear->locked_until));
    }

    private function serializeAccountingPeriod(?AccountingPeriod $period): ?array
    {
        if (! $period) {
            return null;
        }

        return [
            'id' => (int) $period->id,
            'period_year' => (int) $period->period_year,
            'period_month' => (int) $period->period_month,
            'start_date' => $period->start_date?->toDateString(),
            'end_date' => $period->end_date?->toDateString(),
            'status' => (string) $period->status,
            'fiscal_year_id' => $period->fiscal_year_id ? (int) $period->fiscal_year_id : null,
        ];
    }

    private function publicChecklist(array $checklist): array
    {
        unset($checklist['accounting_period']);

        return $checklist;
    }

    private function serializeChecklist(array $checklist): array
    {
        $accountingPeriod = $checklist['accounting_period'] ?? null;
        $checklist['accounting_period'] = $this->serializeAccountingPeriod($accountingPeriod instanceof AccountingPeriod ? $accountingPeriod : null);

        return $checklist;
    }

    private function audit(string $event, string $message, array $metadata = []): void
    {
        $this->auditLogService->logSuccess([
            'event' => $event,
            'module' => 'accounting',
            'action' => $event,
            'message' => $message,
            'metadata' => $metadata,
        ], tenant: true);
    }
}
