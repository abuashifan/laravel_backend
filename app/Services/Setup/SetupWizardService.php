<?php

namespace App\Services\Setup;

use App\Exceptions\ApiException;
use App\Models\Company;
use App\Models\CompanyAccountingSetting;
use App\Models\CompanyModuleSetting;
use App\Models\CompanySetupState;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Services\Audit\AuditLogService;
use App\Services\OpeningBalance\OpeningBalanceBatchService;
use App\Services\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SetupWizardService
{
    private const STATUS_NOT_STARTED = 'not_started';
    private const STATUS_IN_PROGRESS = 'in_progress';
    private const STATUS_READY = 'ready_to_finalize';
    private const STATUS_FINALIZED = 'finalized';
    private const STATUS_REOPENED = 'reopened';

    /** @var array<int, string> */
    private array $steps = [
        'company_profile',
        'module_selection',
        'accounting_settings',
        'chart_of_accounts',
        'account_mappings',
        'opening_fixed_assets',
        'opening_balance_preview',
        'final_review',
        'finalized',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $auditLogService,
        private readonly OpeningBalanceBatchService $openingBalanceBatchService,
    ) {
    }

    public function status(): array
    {
        $state = $this->state();

        return [
            'state' => $this->serializeState($state),
            'steps' => $this->buildSteps($state),
        ];
    }

    public function steps(): array
    {
        $state = $this->state();

        return [
            'steps' => $this->buildSteps($state),
            'state' => $this->serializeState($state),
        ];
    }

    public function updateCurrentStep(array $data): array
    {
        $state = $this->state();
        $step = (string) $data['current_step'];
        $this->assertKnownStep($step);
        $this->assertNotFinalized($state);
        if ($step === 'finalized') {
            throw ApiException::make('SETUP_FINALIZED_STEP_READ_ONLY', 'Finalized step can only be reached through setup finalization.', 422);
        }

        if (isset($data['opening_date'])) {
            $state->opening_date = Carbon::parse((string) $data['opening_date'])->toDateString();
        }

        $state->current_step = $step;
        if (in_array($state->status, [self::STATUS_NOT_STARTED, self::STATUS_REOPENED], true)) {
            $state->status = self::STATUS_IN_PROGRESS;
        }
        $state->save();

        $this->audit('setup.current_step_updated', 'Setup current step updated.', [
            'current_step' => $step,
            'opening_date' => $state->opening_date?->toDateString(),
        ]);

        return $this->status();
    }

    public function validateStep(array $data): array
    {
        $state = $this->state();
        $step = (string) $data['step'];
        $this->assertKnownStep($step);
        $this->assertNotFinalized($state);

        if (isset($data['opening_date'])) {
            $state->opening_date = Carbon::parse((string) $data['opening_date'])->toDateString();
        }

        if ($step === 'opening_fixed_assets' && array_key_exists('confirm_no_opening_fixed_assets', $data)) {
            $metadata = (array) $state->metadata;
            $metadata['opening_fixed_assets_confirmed_none'] = (bool) $data['confirm_no_opening_fixed_assets'];
            $state->metadata = $metadata;
        }

        $result = $this->validateStepKey($state, $step);
        $this->applyValidationResult($state, $step, $result);
        if ($state->status === self::STATUS_NOT_STARTED) {
            $state->status = self::STATUS_IN_PROGRESS;
        }
        $state->last_validated_at = now();
        $state->save();

        $this->audit('setup.step_validated', 'Setup step validated.', [
            'step' => $step,
            'valid' => $result['valid'],
            'errors' => $result['errors'],
        ]);

        return [
            'step' => $step,
            'result' => $result,
            'state' => $this->serializeState($state->refresh()),
        ];
    }

    public function validateAll(): array
    {
        $state = $this->state();
        $this->assertNotFinalized($state);

        $results = [];
        foreach ($this->requiredStepKeys($state) as $step) {
            if ($step === 'final_review') {
                $results[$step] = $this->validateFinalReview($results);
            } else {
                $results[$step] = $this->validateStepKey($state, $step);
            }
            $this->applyValidationResult($state, $step, $results[$step]);
        }

        $valid = collect($results)->every(fn (array $result): bool => (bool) $result['valid']);
        $state->status = $valid ? self::STATUS_READY : self::STATUS_IN_PROGRESS;
        $state->current_step = $valid ? 'final_review' : $this->firstInvalidStep($results, $state->current_step);
        $state->last_validated_at = now();
        $state->save();

        $this->audit('setup.validate_all', 'Setup full validation completed.', [
            'valid' => $valid,
            'invalid_steps' => array_keys(array_filter($results, fn (array $result): bool => ! $result['valid'])),
        ]);

        return [
            'valid' => $valid,
            'results' => $results,
            'state' => $this->serializeState($state->refresh()),
        ];
    }

    public function openingBalancePreview(): array
    {
        $state = $this->state();
        $preview = $this->buildOpeningBalancePreview($state);

        $this->audit('setup.opening_balance_preview', 'Setup opening balance preview generated.', [
            'reconciled' => $preview['reconciled'],
            'blocking_error_count' => count($preview['blocking_errors']),
        ]);

        return $preview;
    }

    public function finalize(): array
    {
        $state = $this->state();
        if ($state->status === self::STATUS_FINALIZED) {
            return [
                'finalized' => true,
                'idempotent' => true,
                'state' => $this->serializeState($state),
            ];
        }

        $validation = $this->validateAll();
        if (! $validation['valid']) {
            throw ApiException::make('SETUP_VALIDATION_FAILED', 'Setup cannot be finalized until all required steps are valid.', 422, [
                'validation' => $validation['results'],
            ]);
        }

        return DB::transaction(function () use ($state) {
            $state = CompanySetupState::query()->lockForUpdate()->findOrFail($state->id);
            if ($state->status === self::STATUS_FINALIZED) {
                return [
                    'finalized' => true,
                    'idempotent' => true,
                    'state' => $this->serializeState($state),
                ];
            }

            $this->assertOpeningBalanceReadyForFinalization($state);

            $state->forceFill([
                'status' => self::STATUS_FINALIZED,
                'current_step' => 'finalized',
                'finalized_at' => now(),
                'finalized_by' => auth()->id(),
                'metadata' => array_merge((array) $state->metadata, [
                    'finalization_source' => 'setup_wizard',
                ]),
            ])->save();

            $this->lockOpeningBalanceRecords();
            $this->lockOpeningFixedAssetRecords();

            $this->audit('setup.finalized', 'Setup finalized.', [
                'company_setup_state_id' => $state->id,
                'opening_date' => $state->opening_date?->toDateString(),
            ]);

            return [
                'finalized' => true,
                'idempotent' => false,
                'state' => $this->serializeState($state->refresh()),
            ];
        });
    }

    public function reopen(string $reason): array
    {
        $state = $this->state();
        if (! $state->opening_date) {
            throw ApiException::make('OPENING_DATE_REQUIRED', 'Opening date is required before setup can be reopened.', 422);
        }

        $blocking = $this->operationalTransactionBlockers($state->opening_date->toDateString());
        if ($blocking !== []) {
            $this->audit('setup.reopen_rejected', 'Setup reopen rejected because operational transactions exist.', [
                'reason' => $reason,
                'blocking' => $blocking,
            ]);

            throw ApiException::make('SETUP_REOPEN_BLOCKED_BY_TRANSACTIONS', 'Setup cannot be reopened after operational transactions exist.', 422, [
                'blocking_transactions' => $blocking,
            ]);
        }

        $state->forceFill([
            'status' => self::STATUS_REOPENED,
            'current_step' => 'final_review',
            'reopened_at' => now(),
            'reopened_by' => auth()->id(),
            'metadata' => array_merge((array) $state->metadata, ['reopen_reason' => $reason]),
        ])->save();

        $this->audit('setup.reopened', 'Setup reopened.', [
            'reason' => $reason,
            'company_setup_state_id' => $state->id,
        ]);

        return [
            'reopened' => true,
            'state' => $this->serializeState($state->refresh()),
        ];
    }

    private function state(): CompanySetupState
    {
        $company = $this->company();

        return CompanySetupState::query()->firstOrCreate(
            ['company_id' => $company->id],
            [
                'status' => self::STATUS_NOT_STARTED,
                'current_step' => 'company_profile',
                'completed_steps' => [],
                'validation_errors' => [],
                'metadata' => [],
            ]
        );
    }

    private function company(): Company
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        return $company;
    }

    private function validateStepKey(CompanySetupState $state, string $step): array
    {
        if ($step === 'opening_fixed_assets' && ! $this->fixedAssetsEnabled()) {
            return $this->validResult(['skipped' => true]);
        }

        return match ($step) {
            'company_profile' => $this->validateCompanyProfile(),
            'module_selection' => $this->validateModuleSelection(),
            'accounting_settings' => $this->validateAccountingSettings($state),
            'chart_of_accounts' => $this->validateChartOfAccounts(),
            'account_mappings' => $this->validateAccountMappings(),
            'opening_fixed_assets' => $this->validateOpeningFixedAssets($state),
            'opening_balance_preview' => $this->validateOpeningBalancePreview($state),
            'final_review' => $this->validateFinalReview([]),
            'finalized' => $state->status === self::STATUS_FINALIZED
                ? $this->validResult()
                : $this->invalidResult('SETUP_NOT_FINALIZED', 'Setup has not been finalized.'),
            default => $this->invalidResult('UNKNOWN_STEP', 'Unknown setup step.'),
        };
    }

    private function validateCompanyProfile(): array
    {
        $company = $this->company();
        $errors = [];
        if (trim((string) $company->name) === '') {
            $errors[] = $this->error('COMPANY_NAME_REQUIRED', 'Company name is required.');
        }
        if (trim((string) $company->code) === '') {
            $errors[] = $this->error('COMPANY_CODE_REQUIRED', 'Company code is required.');
        }
        if ((string) $company->status !== 'active') {
            $errors[] = $this->error('COMPANY_NOT_ACTIVE', 'Company must be active.');
        }

        return $this->resultFromErrors($errors);
    }

    private function validateModuleSelection(): array
    {
        $company = $this->company();
        $setting = CompanyModuleSetting::query()->where('company_id', $company->id)->first();
        if (! $setting) {
            return $this->invalidResult('MODULE_SETTINGS_REQUIRED', 'Module selection must be saved before setup can continue.');
        }

        return $this->validResult(['modules' => $this->moduleFlags($setting)]);
    }

    private function validateAccountingSettings(CompanySetupState $state): array
    {
        $company = $this->company();
        $setting = CompanyAccountingSetting::query()->where('company_id', $company->id)->first();
        $errors = [];

        if (! $setting) {
            $errors[] = $this->error('ACCOUNTING_SETTINGS_REQUIRED', 'Accounting settings must be saved before setup can continue.');
        }
        if (! $state->opening_date) {
            $errors[] = $this->error('OPENING_DATE_REQUIRED', 'Opening date is required.');
        }

        return $this->resultFromErrors($errors, [
            'opening_date' => $state->opening_date?->toDateString(),
            'base_currency' => $setting?->base_currency,
        ]);
    }

    private function validateChartOfAccounts(): array
    {
        if (! Schema::connection('tenant')->hasTable('chart_of_accounts')) {
            return $this->invalidResult('COA_TABLE_NOT_FOUND', 'Chart of accounts table is not available.');
        }

        $counts = ChartOfAccount::query()
            ->where('is_active', true)
            ->selectRaw('account_type, COUNT(*) as total')
            ->groupBy('account_type')
            ->pluck('total', 'account_type')
            ->all();

        $errors = [];
        foreach (['asset', 'liability', 'equity'] as $type) {
            if ((int) ($counts[$type] ?? 0) < 1) {
                $errors[] = $this->error('COA_FOUNDATION_MISSING', "At least one active {$type} account is required.", ['account_type' => $type]);
            }
        }

        return $this->resultFromErrors($errors, ['counts' => $counts]);
    }

    private function validateAccountMappings(): array
    {
        if (! Schema::connection('tenant')->hasTable('account_mappings')) {
            return $this->invalidResult('ACCOUNT_MAPPINGS_TABLE_NOT_FOUND', 'Account mappings table is not available.');
        }

        $modules = $this->requiredMappingModules();
        $errors = [];
        foreach ((array) config('account_mappings.required_mappings', []) as $key => $definition) {
            if (! in_array((string) ($definition['module'] ?? ''), $modules, true)) {
                continue;
            }
            if (! (bool) ($definition['required'] ?? false)) {
                continue;
            }

            $mapping = AccountMapping::query()
                ->where('mapping_key', (string) $key)
                ->where('is_active', true)
                ->first();

            if (! $mapping?->account_id) {
                $errors[] = $this->error('ACCOUNT_MAPPING_MISSING', "Required account mapping [{$key}] is missing.", [
                    'mapping_key' => (string) $key,
                    'module' => (string) ($definition['module'] ?? ''),
                    'label' => $definition['label'] ?? (string) $key,
                ]);
            }
        }

        return $this->resultFromErrors($errors, ['required_modules' => $modules]);
    }

    private function validateOpeningFixedAssets(CompanySetupState $state): array
    {
        if (! $this->fixedAssetsEnabled()) {
            return $this->validResult(['skipped' => true]);
        }

        if (! Schema::connection('tenant')->hasTable('fixed_assets')) {
            return $this->invalidResult('FIXED_ASSET_MODULE_NOT_AVAILABLE', 'Fixed asset module tables are not available.');
        }

        $openingAssets = DB::connection('tenant')->table('fixed_assets')
            ->where('source_type', 'opening_import')
            ->count();

        $metadata = (array) $state->metadata;
        $confirmedNone = (bool) ($metadata['opening_fixed_assets_confirmed_none'] ?? false);
        if ($openingAssets < 1 && ! $confirmedNone) {
            return $this->invalidResult('OPENING_FIXED_ASSETS_NOT_CONFIRMED', 'Opening fixed assets must be imported or explicitly confirmed as none.');
        }

        return $this->validResult([
            'opening_asset_count' => $openingAssets,
            'confirmed_none' => $confirmedNone,
            'totals' => $this->openingFixedAssetTotals(),
        ]);
    }

    private function validateOpeningBalancePreview(CompanySetupState $state): array
    {
        $preview = $this->buildOpeningBalancePreview($state);
        if ($preview['blocking_errors'] !== []) {
            return [
                'valid' => false,
                'errors' => $preview['blocking_errors'],
                'warnings' => $preview['warnings'],
                'metadata' => ['preview' => $preview],
            ];
        }

        return $this->validResult(['preview' => $preview]);
    }

    private function validateFinalReview(array $results): array
    {
        foreach ($results as $step => $result) {
            if (! (bool) ($result['valid'] ?? false)) {
                return $this->invalidResult('REQUIRED_STEP_INVALID', 'Final review requires all previous required steps to be valid.', [
                    'invalid_step' => $step,
                ]);
            }
        }

        return $this->validResult();
    }

    private function buildOpeningBalancePreview(CompanySetupState $state): array
    {
        $blocking = [];
        $warnings = [];
        if (! Schema::connection('tenant')->hasTable('opening_balance_batches')) {
            $blocking[] = $this->error('OPENING_BALANCE_MODULE_NOT_IMPLEMENTED', 'Opening Balance persistence is not implemented yet.');
            return [
                'state' => $this->serializeState($state),
                'implemented' => false,
                'reconciled' => false,
                'blocking_errors' => $blocking,
                'warnings' => $warnings,
                'opening_balance_batch' => null,
                'opening_balance_totals' => ['debit' => 0.0, 'credit' => 0.0, 'difference' => 0.0],
                'fixed_asset_totals' => $this->fixedAssetsEnabled() ? $this->openingFixedAssetTotals() : null,
            ];
        }

        $batch = $this->openingBalanceBatchService->latestActiveBatch();
        if (! $batch) {
            $blocking[] = $this->error('OPENING_BALANCE_BATCH_REQUIRED', 'Opening balance batch is required.');
            return [
                'state' => $this->serializeState($state),
                'implemented' => true,
                'reconciled' => false,
                'blocking_errors' => $blocking,
                'warnings' => $warnings,
                'opening_balance_batch' => null,
                'opening_balance_totals' => ['debit' => 0.0, 'credit' => 0.0, 'difference' => 0.0],
                'fixed_asset_totals' => $this->fixedAssetsEnabled() ? $this->openingFixedAssetTotals() : null,
            ];
        }

        $preview = $this->openingBalanceBatchService->preview($batch);
        $blocking = array_values(array_merge($blocking, $preview['blocking_errors']));
        $warnings = array_values(array_merge($warnings, $preview['warnings']));
        $fixedAssetTotals = $this->fixedAssetsEnabled() ? $this->openingFixedAssetTotals() : null;
        if ($this->fixedAssetsEnabled() && $fixedAssetTotals && abs($fixedAssetTotals['net_book_value'] - ($fixedAssetTotals['cost'] - $fixedAssetTotals['accumulated_depreciation'])) > 0.005) {
            $blocking[] = $this->error('FIXED_ASSET_NBV_MISMATCH', 'Opening fixed asset net book value does not match cost minus accumulated depreciation.');
        } else {
            $fixedAssetTotals = $preview['fixed_asset_totals'] ?? $fixedAssetTotals;
        }

        return [
            'state' => $this->serializeState($state),
            'implemented' => true,
            'reconciled' => $blocking === [],
            'blocking_errors' => $blocking,
            'warnings' => $warnings,
            'opening_balance_batch' => $batch,
            'opening_balance_totals' => [
                'debit' => $preview['total_debit'],
                'credit' => $preview['total_credit'],
                'difference' => $preview['difference'],
            ],
            'fixed_asset_totals' => $fixedAssetTotals,
            'opening_balance_preview' => $preview,
        ];
    }

    private function assertOpeningBalanceReadyForFinalization(CompanySetupState $state): void
    {
        $preview = $this->buildOpeningBalancePreview($state);
        if ($preview['blocking_errors'] !== []) {
            throw ApiException::make('OPENING_BALANCE_NOT_READY', 'Opening balance is not ready for setup finalization.', 422, [
                'blocking_errors' => $preview['blocking_errors'],
            ]);
        }

        $batch = $this->openingBalanceBatchService->latestActiveBatch();
        if (! $batch) {
            throw ApiException::make('OPENING_BALANCE_BATCH_REQUIRED', 'Opening balance batch is required.', 422);
        }

        $posted = $this->openingBalanceBatchService->post($batch);
        if ($posted->status !== 'locked') {
            $this->openingBalanceBatchService->lock($posted);
        }
    }

    private function lockOpeningBalanceRecords(): void
    {
        if (! Schema::connection('tenant')->hasTable('opening_balance_batches')) {
            return;
        }

        DB::connection('tenant')->table('opening_balance_batches')
            ->whereIn('status', ['posted', 'validated'])
            ->update([
                'status' => 'locked',
                'locked_at' => now(),
                'locked_by' => auth()->id(),
                'updated_at' => now(),
            ]);
    }

    private function lockOpeningFixedAssetRecords(): void
    {
        if (! Schema::connection('tenant')->hasTable('fixed_assets')) {
            return;
        }

        DB::connection('tenant')->table('fixed_assets')
            ->where('source_type', 'opening_import')
            ->update([
                'metadata' => DB::raw("json_set(COALESCE(metadata, '{}'), '$.setup_locked', true)"),
                'updated_at' => now(),
            ]);
    }

    private function operationalTransactionBlockers(string $openingDate): array
    {
        $checks = [
            ['journal_entries', 'journal_date', fn ($query) => $query->where('status', '!=', 'void')->where(function ($q) { $q->whereNull('source_type')->orWhere('source_type', '!=', 'opening_balance'); })],
            ['sales_invoices', 'invoice_date', fn ($query) => $query->where('status', '!=', 'void')],
            ['sales_receipts', 'receipt_date', fn ($query) => $query->where('status', '!=', 'void')],
            ['vendor_bills', 'bill_date', fn ($query) => $query->where('status', '!=', 'void')],
            ['vendor_payments', 'payment_date', fn ($query) => $query->where('status', '!=', 'void')],
            ['cash_receipts', 'receipt_date', fn ($query) => $query->where('status', '!=', 'void')],
            ['cash_payments', 'payment_date', fn ($query) => $query->where('status', '!=', 'void')],
            ['bank_transfers', 'transfer_date', fn ($query) => $query->where('status', '!=', 'void')],
            ['stock_movements', 'movement_date', fn ($query) => $query->where('status', '!=', 'void')->where(function ($q) { $q->whereNull('movement_type')->orWhere('movement_type', '!=', 'opening_stock'); })],
            ['fixed_asset_transactions', 'transaction_date', fn ($query) => $query->where(function ($q) { $q->whereNull('source_type')->orWhere('source_type', '!=', 'opening_import'); })],
        ];

        $blocking = [];
        foreach ($checks as [$table, $dateColumn, $scope]) {
            if (! Schema::connection('tenant')->hasTable($table) || ! Schema::connection('tenant')->hasColumn($table, $dateColumn)) {
                continue;
            }
            $query = DB::connection('tenant')->table($table)->where($dateColumn, '>', $openingDate);
            $scope($query);
            $count = $query->count();
            if ($count > 0) {
                $blocking[] = ['table' => $table, 'count' => $count];
            }
        }

        return $blocking;
    }

    private function requiredStepKeys(CompanySetupState $state): array
    {
        return array_values(array_filter($this->steps, function (string $step): bool {
            if ($step === 'finalized') {
                return false;
            }
            if ($step === 'opening_fixed_assets') {
                return $this->fixedAssetsEnabled();
            }

            return true;
        }));
    }

    private function buildSteps(CompanySetupState $state): array
    {
        $required = $this->requiredStepKeys($state);
        $completed = (array) $state->completed_steps;
        $errors = (array) $state->validation_errors;

        return array_map(fn (string $step): array => [
            'key' => $step,
            'order' => $this->stepOrder($step),
            'active' => in_array($step, $required, true) || $step === 'finalized',
            'skipped' => $step === 'opening_fixed_assets' && ! $this->fixedAssetsEnabled(),
            'completed' => in_array($step, $completed, true) || ($step === 'finalized' && $state->status === self::STATUS_FINALIZED),
            'current' => $state->current_step === $step,
            'errors' => $errors[$step] ?? [],
        ], $this->steps);
    }

    private function applyValidationResult(CompanySetupState $state, string $step, array $result): void
    {
        $completed = collect((array) $state->completed_steps);
        $errors = (array) $state->validation_errors;

        if ((bool) $result['valid']) {
            $completed = $completed->push($step)->unique()->values();
            unset($errors[$step]);
        } else {
            $completed = $completed->reject(fn (string $value): bool => $value === $step)->values();
            $errors[$step] = $result['errors'];
        }

        $state->completed_steps = $completed->all();
        $state->validation_errors = $errors;
    }

    private function moduleFlags(?CompanyModuleSetting $setting = null): array
    {
        $setting ??= CompanyModuleSetting::query()->where('company_id', $this->company()->id)->first();

        return [
            'sales_enabled' => (bool) ($setting?->sales_enabled ?? false),
            'purchase_enabled' => (bool) ($setting?->purchase_enabled ?? false),
            'cash_bank_enabled' => (bool) ($setting?->cash_bank_enabled ?? false),
            'inventory_enabled' => (bool) ($setting?->inventory_enabled ?? false),
            'warehouse_enabled' => (bool) ($setting?->warehouse_enabled ?? false),
            'fixed_asset_enabled' => (bool) ($setting?->fixed_asset_enabled ?? false),
            'approval_enabled' => (bool) ($setting?->approval_enabled ?? false),
            'tax_enabled' => (bool) ($setting?->tax_enabled ?? false),
            'reports_enabled' => (bool) ($setting?->reports_enabled ?? false),
        ];
    }

    private function requiredMappingModules(): array
    {
        $flags = $this->moduleFlags();
        $modules = ['opening_balance'];
        if ($flags['sales_enabled']) $modules[] = 'sales';
        if ($flags['purchase_enabled']) $modules[] = 'purchase';
        if ($flags['cash_bank_enabled']) $modules[] = 'cash_bank';
        if ($flags['inventory_enabled']) $modules[] = 'inventory';
        if ($flags['fixed_asset_enabled']) $modules[] = 'fixed_assets';

        return array_values(array_unique($modules));
    }

    private function fixedAssetsEnabled(): bool
    {
        return $this->moduleFlags()['fixed_asset_enabled'];
    }

    private function openingFixedAssetTotals(): array
    {
        if (! Schema::connection('tenant')->hasTable('fixed_assets')) {
            return [
                'count' => 0,
                'cost' => 0.0,
                'accumulated_depreciation' => 0.0,
                'net_book_value' => 0.0,
            ];
        }

        $row = DB::connection('tenant')->table('fixed_assets')
            ->where('source_type', 'opening_import')
            ->selectRaw('COUNT(*) as total_count, COALESCE(SUM(acquisition_cost), 0) as total_cost, COALESCE(SUM(accumulated_depreciation), 0) as total_accumulated, COALESCE(SUM(net_book_value), 0) as total_nbv')
            ->first();

        return [
            'count' => (int) ($row->total_count ?? 0),
            'cost' => round((float) ($row->total_cost ?? 0), 2),
            'accumulated_depreciation' => round((float) ($row->total_accumulated ?? 0), 2),
            'net_book_value' => round((float) ($row->total_nbv ?? 0), 2),
        ];
    }

    private function firstInvalidStep(array $results, string $fallback): string
    {
        foreach ($results as $step => $result) {
            if (! (bool) $result['valid']) {
                return $step;
            }
        }

        return $fallback;
    }

    private function stepOrder(string $step): int
    {
        $order = array_search($step, $this->steps, true);

        return $order === false ? 999 : (int) $order;
    }

    private function assertKnownStep(string $step): void
    {
        if (! in_array($step, $this->steps, true)) {
            throw ApiException::make('UNKNOWN_SETUP_STEP', 'Unknown setup step.', 422, [
                'step' => ['Unknown setup step.'],
            ]);
        }
    }

    private function assertNotFinalized(CompanySetupState $state): void
    {
        if ($state->status === self::STATUS_FINALIZED) {
            throw ApiException::make('SETUP_ALREADY_FINALIZED', 'Finalized setup cannot be changed from setup wizard APIs.', 422);
        }
    }

    private function serializeState(CompanySetupState $state): array
    {
        return [
            'id' => (int) $state->id,
            'company_id' => (int) $state->company_id,
            'status' => (string) $state->status,
            'current_step' => (string) $state->current_step,
            'opening_date' => $state->opening_date?->toDateString(),
            'completed_steps' => array_values((array) $state->completed_steps),
            'validation_errors' => (array) $state->validation_errors,
            'last_validated_at' => $state->last_validated_at?->toIso8601String(),
            'finalized_at' => $state->finalized_at?->toIso8601String(),
            'finalized_by' => $state->finalized_by ? (int) $state->finalized_by : null,
            'reopened_at' => $state->reopened_at?->toIso8601String(),
            'reopened_by' => $state->reopened_by ? (int) $state->reopened_by : null,
            'metadata' => (array) $state->metadata,
        ];
    }

    private function resultFromErrors(array $errors, array $metadata = []): array
    {
        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => [],
            'metadata' => $metadata,
        ];
    }

    private function validResult(array $metadata = []): array
    {
        return [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'metadata' => $metadata,
        ];
    }

    private function invalidResult(string $code, string $message, array $metadata = []): array
    {
        return [
            'valid' => false,
            'errors' => [$this->error($code, $message, $metadata)],
            'warnings' => [],
            'metadata' => $metadata,
        ];
    }

    private function error(string $code, string $message, array $metadata = []): array
    {
        return array_filter([
            'code' => $code,
            'message' => $message,
            'metadata' => $metadata ?: null,
        ], fn ($value) => $value !== null);
    }

    private function audit(string $event, string $message, array $metadata = []): void
    {
        $this->auditLogService->logSuccess([
            'event' => $event,
            'module' => 'setup',
            'action' => $event,
            'message' => $message,
            'metadata' => $metadata,
        ], tenant: true);
    }
}
