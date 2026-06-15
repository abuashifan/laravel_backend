<?php

namespace App\Services\OpeningBalance;

use App\Exceptions\ApiException;
use App\Models\CompanyModuleSetting;
use App\Models\FiscalYear;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\OpeningBalanceBatch as OpeningBalanceBatchModel;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Tenant\TenantContext;
use App\Services\Transactions\TransactionVoidEffectService;
use App\Support\DocumentNumbering\DocumentType;
use App\Support\OpeningBalance\OpeningBalanceBatch as OpeningBalanceBatchDto;
use App\Support\OpeningBalance\OpeningBalanceLine as OpeningBalanceLineDto;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OpeningBalanceBatchService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly AuditLogService $auditLogService,
        private readonly OpeningBalanceService $openingBalanceService,
        private readonly TransactionVoidEffectService $voidEffectService,
    ) {
    }

    public function status(): array
    {
        $batch = OpeningBalanceBatchModel::query()
            ->with('journalEntry')
            ->where('status', '!=', 'voided')
            ->latest('id')
            ->first();

        return [
            'status' => $batch?->status ?? 'not_started',
            'batch' => $batch,
            'has_posted_or_locked_batch' => OpeningBalanceBatchModel::query()->whereIn('status', ['posted', 'locked'])->exists(),
        ];
    }

    public function list(): Collection
    {
        return OpeningBalanceBatchModel::query()
            ->withCount('lines')
            ->orderByDesc('opening_date')
            ->orderByDesc('id')
            ->get();
    }

    public function create(array $data): OpeningBalanceBatchModel
    {
        $company = $this->company();
        if (OpeningBalanceBatchModel::query()->whereIn('status', ['draft', 'validated', 'posted', 'locked', 'reopened'])->exists()) {
            throw ApiException::make('OPENING_BALANCE_ACTIVE_BATCH_EXISTS', 'Only one active opening balance batch is allowed.', 422);
        }

        $openingDate = Carbon::parse((string) $data['opening_date'])->toDateString();

        $batch = OpeningBalanceBatchModel::query()->create([
            'batch_number' => $this->documentNumberService->generate($company, DocumentType::OPENING_BALANCE, $openingDate),
            'opening_date' => $openingDate,
            'fiscal_year' => $data['fiscal_year'] ?? (int) Carbon::parse($openingDate)->format('Y'),
            'type' => $data['type'] ?? 'standard',
            'status' => 'draft',
            'description' => $data['description'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);

        $this->audit('opening_balance.batch_created', 'Opening balance batch created.', $batch);

        return $batch->refresh()->load('lines');
    }

    public function update(OpeningBalanceBatchModel $batch, array $data): OpeningBalanceBatchModel
    {
        $this->assertEditable($batch);

        $payload = [];
        foreach (['fiscal_year', 'type', 'description', 'metadata'] as $key) {
            if (array_key_exists($key, $data)) {
                $payload[$key] = $data[$key];
            }
        }
        if (array_key_exists('opening_date', $data)) {
            $payload['opening_date'] = Carbon::parse((string) $data['opening_date'])->toDateString();
        }

        $batch->fill($payload)->save();
        $this->recalculateTotals($batch);
        $this->audit('opening_balance.batch_updated', 'Opening balance batch updated.', $batch);

        return $batch->refresh()->load('lines');
    }

    public function replaceLines(OpeningBalanceBatchModel $batch, array $lines): OpeningBalanceBatchModel
    {
        $this->assertEditable($batch);

        return DB::connection('tenant')->transaction(function () use ($batch, $lines) {
            $batch->lines()->delete();
            $rows = [];
            foreach ($lines as $line) {
                $debit = round((float) ($line['debit'] ?? 0), 2);
                $credit = round((float) ($line['credit'] ?? 0), 2);
                if ($debit <= 0 && $credit <= 0) {
                    continue;
                }

                $account = ChartOfAccount::query()->findOrFail((int) $line['account_id']);
                $rows[] = [
                    'opening_balance_batch_id' => $batch->id,
                    'account_id' => $account->id,
                    'account_code' => $account->account_code,
                    'account_name' => $account->account_name,
                    'account_type' => $account->account_type,
                    'debit' => $debit,
                    'credit' => $credit,
                    'description' => $line['description'] ?? 'Opening balance',
                    'source_type' => $line['source_type'] ?? null,
                    'source_id' => $line['source_id'] ?? null,
                    'source_line_id' => $line['source_line_id'] ?? null,
                    'is_system_generated' => false,
                    'metadata' => isset($line['metadata']) ? json_encode((array) $line['metadata']) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                $batch->lines()->insert($rows);
            }

            $this->recalculateTotals($batch);
            $batch->forceFill([
                'status' => 'draft',
                'validated_at' => null,
                'validated_by' => null,
            ])->save();

            $this->audit('opening_balance.lines_replaced', 'Opening balance lines replaced.', $batch, [
                'line_count' => count($rows),
            ]);

            return $batch->refresh()->load('lines.account');
        });
    }

    public function validate(OpeningBalanceBatchModel $batch): array
    {
        $preview = $this->preview($batch);
        $valid = $preview['blocking_errors'] === [];

        $batch->forceFill([
            'status' => $valid ? 'validated' : $batch->status,
            'validated_at' => now(),
            'validated_by' => auth()->id(),
            'metadata' => array_merge((array) $batch->metadata, [
                'last_validation' => [
                    'valid' => $valid,
                    'blocking_errors' => $preview['blocking_errors'],
                    'warnings' => $preview['warnings'],
                ],
            ]),
        ])->save();

        $this->audit('opening_balance.validated', 'Opening balance validation completed.', $batch, [
            'valid' => $valid,
            'blocking_errors' => $preview['blocking_errors'],
        ]);

        return [
            'valid' => $valid,
            'batch' => $batch->refresh()->load('lines'),
            'preview' => $preview,
        ];
    }

    public function preview(OpeningBalanceBatchModel $batch): array
    {
        $batch->loadMissing('lines.account');
        $manualLines = $this->manualLineArrays($batch);
        $systemLines = $this->fixedAssetSystemLines();
        $fixedAssetTotals = $this->openingFixedAssetTotals();
        $allLines = array_values(array_merge($manualLines, $systemLines));

        $dto = $this->dto($batch, $allLines);
        $baseValidation = $this->openingBalanceService->validate($dto);
        $blocking = $this->validationErrorsToBlocking($baseValidation['errors'] ?? []);
        $warnings = $baseValidation['warnings'] ?? [];
        $blocking = array_values(array_merge($blocking, $this->additionalBlockingErrors($batch, $manualLines, $systemLines)));

        return [
            'batch' => $batch->refresh()->load('lines.account'),
            'manual_lines' => $manualLines,
            'system_lines' => $systemLines,
            'fixed_asset_totals' => $fixedAssetTotals,
            'total_debit' => round($dto->totalDebit(), 2),
            'total_credit' => round($dto->totalCredit(), 2),
            'difference' => round($dto->difference(), 2),
            'validation' => [
                'valid' => $blocking === [],
                'errors' => $blocking,
                'warnings' => $warnings,
            ],
            'blocking_errors' => $blocking,
            'warnings' => $warnings,
            'journal_payload' => $this->journalPayload($batch, $allLines),
        ];
    }

    public function post(OpeningBalanceBatchModel $batch): OpeningBalanceBatchModel
    {
        if ($batch->postedOrLocked()) {
            return $batch->refresh()->load('lines', 'journalEntry');
        }
        if ($batch->status === 'voided') {
            throw ApiException::make('OPENING_BALANCE_VOIDED', 'Voided opening balance cannot be posted.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($batch) {
            $batch = OpeningBalanceBatchModel::query()->with('lines')->lockForUpdate()->findOrFail($batch->id);
            if ($batch->postedOrLocked()) {
                return $batch->refresh()->load('lines', 'journalEntry');
            }

            $this->audit('opening_balance.post_attempted', 'Opening balance posting attempted.', $batch);

            $validation = $this->validate($batch);
            if (! $validation['valid']) {
                $this->audit('opening_balance.post_rejected', 'Opening balance posting rejected.', $batch, [
                    'blocking_errors' => $validation['preview']['blocking_errors'],
                ]);
                throw ApiException::make('OPENING_BALANCE_VALIDATION_FAILED', 'Opening balance cannot be posted until validation passes.', 422, [
                    'blocking_errors' => $validation['preview']['blocking_errors'],
                ]);
            }

            if (OpeningBalanceBatchModel::query()->where('id', '!=', $batch->id)->whereIn('status', ['posted', 'locked'])->exists()) {
                throw ApiException::make('OPENING_BALANCE_ALREADY_POSTED', 'Another opening balance batch is already posted or locked.', 422);
            }

            $preview = $this->preview($batch);
            $journal = $this->createOpeningJournal($batch, $preview['journal_payload']);

            $batch->forceFill([
                'status' => 'posted',
                'journal_entry_id' => $journal->id,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
                'metadata' => array_merge((array) $batch->metadata, [
                    'posted_journal_number' => $journal->journal_number,
                ]),
            ])->save();

            $this->audit('opening_balance.posted', 'Opening balance posted.', $batch->refresh(), [
                'journal_entry_id' => $journal->id,
            ]);

            return $batch->refresh()->load('lines', 'journalEntry');
        });
    }

    public function lock(OpeningBalanceBatchModel $batch): OpeningBalanceBatchModel
    {
        if ($batch->status === 'locked') {
            return $batch->refresh();
        }
        if ($batch->status !== 'posted') {
            throw ApiException::make('OPENING_BALANCE_NOT_POSTED', 'Only posted opening balance can be locked.', 422);
        }

        $batch->forceFill([
            'status' => 'locked',
            'locked_at' => now(),
            'locked_by' => auth()->id(),
        ])->save();

        $this->audit('opening_balance.locked', 'Opening balance locked.', $batch);

        return $batch->refresh()->load('lines', 'journalEntry');
    }

    public function reopen(OpeningBalanceBatchModel $batch, string $reason): OpeningBalanceBatchModel
    {
        $reason = $this->voidEffectService->requireReason($reason);
        $this->audit('opening_balance.reopen_attempted', 'Opening balance reopen attempted.', $batch, ['reason' => $reason]);

        if (! in_array((string) $batch->status, ['posted', 'locked'], true)) {
            throw ApiException::make('OPENING_BALANCE_NOT_REOPENABLE', 'Only posted or locked opening balance can be reopened.', 422);
        }

        $this->assertPeriodCanReopen($batch);
        $blocking = $this->operationalTransactionBlockers($batch->opening_date->toDateString());
        if ($blocking !== []) {
            $this->audit('opening_balance.reopen_rejected', 'Opening balance reopen rejected.', $batch, [
                'reason' => $reason,
                'blocking_transactions' => $blocking,
            ]);
            throw ApiException::make('OPENING_BALANCE_REOPEN_BLOCKED_BY_TRANSACTIONS', 'Opening balance cannot be reopened after operational transactions exist.', 422, [
                'blocking_transactions' => $blocking,
            ]);
        }

        $this->voidEffectService->voidJournalById((int) $batch->journal_entry_id, $reason);

        $batch->forceFill([
            'status' => 'reopened',
            'reopened_at' => now(),
            'reopened_by' => auth()->id(),
            'metadata' => array_merge((array) $batch->metadata, ['reopen_reason' => $reason]),
        ])->save();

        $this->audit('opening_balance.reopened', 'Opening balance reopened.', $batch, ['reason' => $reason]);

        return $batch->refresh()->load('lines', 'journalEntry');
    }

    public function latestActiveBatch(): ?OpeningBalanceBatchModel
    {
        return OpeningBalanceBatchModel::query()
            ->where('status', '!=', 'voided')
            ->latest('id')
            ->first();
    }

    private function createOpeningJournal(OpeningBalanceBatchModel $batch, array $payload): JournalEntry
    {
        $company = $this->company();
        $journal = JournalEntry::query()->create([
            'journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, $batch->opening_date->toDateString()),
            'journal_date' => $payload['journal_date'],
            'description' => $payload['description'],
            'status' => 'posted',
            'revision_no' => 1,
            'source_type' => 'opening_balance',
            'source_id' => $batch->id,
            'source_number' => $batch->batch_number,
            'source_revision' => 1,
            'source_module' => 'opening_balance',
            'source_batch_id' => $batch->id,
            'is_system_generated' => true,
            'created_by' => auth()->id(),
            'posted_by' => auth()->id(),
            'posted_at' => now(),
            'metadata' => $payload['metadata'] ?? null,
        ]);

        $rows = [];
        foreach ($payload['lines'] as $index => $line) {
            $rows[] = [
                'account_id' => (int) $line['account_id'],
                'description' => $line['description'] ?? 'Opening balance',
                'debit' => round((float) ($line['debit'] ?? 0), 2),
                'credit' => round((float) ($line['credit'] ?? 0), 2),
                'line_order' => $index + 1,
                'metadata' => $line['metadata'] ?? null,
            ];
        }
        $journal->lines()->createMany($rows);

        return $journal->refresh()->load('lines');
    }

    private function journalPayload(OpeningBalanceBatchModel $batch, array $lines): array
    {
        $dto = $this->dto($batch, $lines);
        $payload = $this->openingBalanceService->prepareJournalPayload($dto);
        $payload['document_number'] = $batch->batch_number;
        $payload['description'] = $batch->description ?: 'Opening balance '.$batch->batch_number;
        $payload['metadata'] = array_merge((array) ($payload['metadata'] ?? []), [
            'opening_balance_batch_id' => $batch->id,
            'batch_number' => $batch->batch_number,
        ]);

        return $payload;
    }

    private function dto(OpeningBalanceBatchModel $batch, array $lines): OpeningBalanceBatchDto
    {
        $dtoLines = array_map(fn (array $line): OpeningBalanceLineDto => OpeningBalanceLineDto::make(
            $line['account_id'] ?? null,
            $line['account_code'] ?? null,
            $line['account_name'] ?? null,
            $line['account_type'] ?? null,
            $line['debit'] ?? 0,
            $line['credit'] ?? 0,
            $line['description'] ?? null,
            (array) ($line['metadata'] ?? [])
        ), $lines);

        return new OpeningBalanceBatchDto(
            $batch->batch_number,
            $batch->opening_date?->toDateString(),
            $batch->fiscal_year ? (int) $batch->fiscal_year : null,
            (string) $batch->type,
            $dtoLines,
            $batch->description,
            (array) $batch->metadata
        );
    }

    private function manualLineArrays(OpeningBalanceBatchModel $batch): array
    {
        return $batch->lines->map(fn ($line): array => [
            'id' => (int) $line->id,
            'account_id' => (int) $line->account_id,
            'account_code' => $line->account_code,
            'account_name' => $line->account_name,
            'account_type' => $line->account_type,
            'debit' => round((float) $line->debit, 2),
            'credit' => round((float) $line->credit, 2),
            'description' => $line->description,
            'source_type' => $line->source_type,
            'source_id' => $line->source_id,
            'source_line_id' => $line->source_line_id,
            'is_system_generated' => (bool) $line->is_system_generated,
            'metadata' => (array) $line->metadata,
        ])->all();
    }

    private function fixedAssetSystemLines(): array
    {
        if (! $this->fixedAssetsEnabled() || ! Schema::connection('tenant')->hasTable('fixed_assets')) {
            return [];
        }

        $totals = $this->openingFixedAssetTotals();
        $cost = $totals['cost'];
        $accumulated = $totals['accumulated_depreciation'];
        if ($cost <= 0 && $accumulated <= 0) {
            return [];
        }

        $lines = [];
        if ($cost > 0) {
            $lines[] = $this->systemLine($this->mappingAccount('fixed_assets.cost'), $cost, 0, 'Opening fixed asset cost', [
                'source_type' => 'opening_fixed_assets',
                'fixed_asset_total_cost' => $cost,
            ]);
        }
        if ($accumulated > 0) {
            $lines[] = $this->systemLine($this->mappingAccount('fixed_assets.accumulated_depreciation'), 0, $accumulated, 'Opening accumulated depreciation', [
                'source_type' => 'opening_fixed_assets',
                'fixed_asset_total_accumulated_depreciation' => $accumulated,
            ]);
        }

        return $lines;
    }

    private function openingFixedAssetTotals(): array
    {
        if (! $this->fixedAssetsEnabled() || ! Schema::connection('tenant')->hasTable('fixed_assets')) {
            return [
                'count' => 0,
                'cost' => 0.0,
                'accumulated_depreciation' => 0.0,
                'net_book_value' => 0.0,
            ];
        }

        $totals = DB::connection('tenant')->table('fixed_assets')
            ->where('source_type', 'opening_import')
            ->selectRaw('COUNT(*) as total_count, COALESCE(SUM(acquisition_cost), 0) as cost, COALESCE(SUM(accumulated_depreciation), 0) as accumulated, COALESCE(SUM(net_book_value), 0) as nbv')
            ->first();

        return [
            'count' => (int) ($totals->total_count ?? 0),
            'cost' => round((float) ($totals->cost ?? 0), 2),
            'accumulated_depreciation' => round((float) ($totals->accumulated ?? 0), 2),
            'net_book_value' => round((float) ($totals->nbv ?? 0), 2),
        ];
    }

    private function systemLine(int $accountId, float $debit, float $credit, string $description, array $metadata = []): array
    {
        $account = ChartOfAccount::query()->findOrFail($accountId);

        return [
            'account_id' => (int) $account->id,
            'account_code' => $account->account_code,
            'account_name' => $account->account_name,
            'account_type' => $account->account_type,
            'debit' => $debit,
            'credit' => $credit,
            'description' => $description,
            'source_type' => 'opening_fixed_assets',
            'source_id' => null,
            'source_line_id' => null,
            'is_system_generated' => true,
            'metadata' => $metadata,
        ];
    }

    private function additionalBlockingErrors(OpeningBalanceBatchModel $batch, array $manualLines, array $systemLines): array
    {
        $errors = [];
        if (OpeningBalanceBatchModel::query()->where('id', '!=', $batch->id)->whereIn('status', ['posted', 'locked'])->exists()) {
            $errors[] = $this->error('OPENING_BALANCE_ALREADY_POSTED', 'Another opening balance batch is already posted or locked.');
        }

        if (! $this->mappingAccount('opening_balance.equity', required: false)) {
            $errors[] = $this->error('OPENING_BALANCE_EQUITY_MAPPING_MISSING', 'Opening balance equity account mapping is required.');
        }

        if ($systemLines !== []) {
            $systemAccountIds = collect($systemLines)->pluck('account_id')->unique()->values()->all();
            $duplicateManual = collect($manualLines)->filter(fn (array $line): bool => in_array((int) $line['account_id'], $systemAccountIds, true))->values();
            if ($duplicateManual->isNotEmpty()) {
                $errors[] = $this->error('FIXED_ASSET_CONTROL_DUPLICATE', 'Manual opening balance lines cannot duplicate fixed asset system-generated control accounts.', [
                    'account_ids' => $duplicateManual->pluck('account_id')->unique()->values()->all(),
                ]);
            }
        }

        return $errors;
    }

    private function validationErrorsToBlocking(array $errors): array
    {
        return array_map(fn (string $code): array => $this->error($code, $this->messageForValidationCode($code)), $errors);
    }

    private function messageForValidationCode(string $code): string
    {
        return match (true) {
            str_contains($code, 'LINE_HAS_BOTH_DEBIT_AND_CREDIT') => 'A line cannot have both debit and credit.',
            str_contains($code, 'LINE_DEBIT_NEGATIVE') => 'Line debit cannot be negative.',
            str_contains($code, 'LINE_CREDIT_NEGATIVE') => 'Line credit cannot be negative.',
            str_contains($code, 'NOMINAL_ACCOUNT_TYPE_NOT_ALLOWED') => 'Nominal accounts are not allowed in opening balance by default.',
            $code === 'BATCH_MINIMUM_LINES' => 'Opening balance requires at least two non-zero lines.',
            $code === 'BATCH_NOT_BALANCED', $code === 'BATCH_UNBALANCED_NOT_ALLOWED' => 'Opening balance must be balanced.',
            default => $code,
        };
    }

    private function recalculateTotals(OpeningBalanceBatchModel $batch): void
    {
        $totalDebit = round((float) $batch->lines()->sum('debit'), 2);
        $totalCredit = round((float) $batch->lines()->sum('credit'), 2);
        $batch->forceFill([
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'difference' => round($totalDebit - $totalCredit, 2),
        ])->save();
    }

    private function assertEditable(OpeningBalanceBatchModel $batch): void
    {
        if (! $batch->editable()) {
            throw ApiException::make('OPENING_BALANCE_NOT_EDITABLE', 'Only draft or reopened opening balance can be edited.', 422);
        }
    }

    private function assertPeriodCanReopen(OpeningBalanceBatchModel $batch): void
    {
        $company = $this->company();
        $openingDate = $batch->opening_date->toDateString();
        $fy = FiscalYear::query()
            ->where('company_id', $company->id)
            ->where('start_date', '<=', $openingDate)
            ->where('end_date', '>=', $openingDate)
            ->first();

        if ($fy && (string) $fy->status === 'closed') {
            throw ApiException::make('FISCAL_YEAR_CLOSED', 'Fiscal year is closed.', 422);
        }
        if ($fy?->locked_until && Carbon::parse($openingDate)->lte(Carbon::parse($fy->locked_until))) {
            throw ApiException::make('PERIOD_LOCKED', 'Opening balance period is locked.', 422);
        }
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
            ['period_end_runs', 'period', fn ($query) => $query->whereIn('status', ['completed'])],
        ];

        $blocking = [];
        foreach ($checks as [$table, $dateColumn, $scope]) {
            if (! Schema::connection('tenant')->hasTable($table) || ! Schema::connection('tenant')->hasColumn($table, $dateColumn)) {
                continue;
            }
            $query = DB::connection('tenant')->table($table);
            if ($dateColumn === 'period') {
                $query->where($dateColumn, '>', Carbon::parse($openingDate)->format('Y-m'));
            } else {
                $query->where($dateColumn, '>', $openingDate);
            }
            $scope($query);
            $count = $query->count();
            if ($count > 0) {
                $blocking[] = ['table' => $table, 'count' => $count];
            }
        }

        return $blocking;
    }

    private function mappingAccount(string $key, bool $required = true): ?int
    {
        $mapping = AccountMapping::query()->where('mapping_key', $key)->where('is_active', true)->first();
        if ($mapping?->account_id) {
            return (int) $mapping->account_id;
        }
        if ($required) {
            throw ApiException::make('ACCOUNT_MAPPING_MISSING', "Account mapping [{$key}] is required.", 422);
        }

        return null;
    }

    private function fixedAssetsEnabled(): bool
    {
        $company = $this->company();
        return (bool) CompanyModuleSetting::query()->where('company_id', $company->id)->value('fixed_asset_enabled');
    }

    private function company()
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        return $company;
    }

    private function error(string $code, string $message, array $metadata = []): array
    {
        return array_filter([
            'code' => $code,
            'message' => $message,
            'metadata' => $metadata ?: null,
        ], fn ($value) => $value !== null);
    }

    private function audit(string $event, string $message, OpeningBalanceBatchModel $batch, array $metadata = []): void
    {
        $this->auditLogService->logSuccess([
            'event' => $event,
            'module' => 'opening_balance',
            'action' => $event,
            'message' => $message,
            'record_type' => 'opening_balance_batch',
            'record_id' => $batch->id,
            'record_number' => $batch->batch_number,
            'metadata' => array_merge([
                'opening_balance_batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'opening_date' => $batch->opening_date?->toDateString(),
                'journal_entry_id' => $batch->journal_entry_id,
                'user_id' => auth()->id(),
            ], $metadata),
        ], tenant: true);
    }
}
