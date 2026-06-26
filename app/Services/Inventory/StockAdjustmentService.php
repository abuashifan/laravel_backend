<?php

namespace App\Services\Inventory;

use App\Enums\SourceType;
use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockAdjustmentLine;
use App\Models\Tenant\StockMovement;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Settings\CompanySettingService;
use App\Services\Tenant\TenantContext;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class StockAdjustmentService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly StockMovementService $stockMovementService,
        private readonly StockBalanceService $stockBalanceService,
        private readonly InventoryQuantityService $qtyService,
        private readonly StockMovementValidationService $movementValidation,
        private readonly AuditLogService $auditLogService,
        private readonly CompanySettingService $companySettingService,
    ) {
    }

    public function list(array $filters = []): Collection
    {
        $q = StockAdjustment::query()->with('warehouse')->withCount('lines');

        if (! empty($filters['status'])) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $filters['status']))));
            if ($statuses !== []) {
                $q->whereIn('status', $statuses);
            }
        }

        if (! empty($filters['warehouse_id'])) $q->where('warehouse_id', (int) $filters['warehouse_id']);

        if (! empty($filters['date_from'])) {
            $q->whereDate('adjustment_date', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $q->whereDate('adjustment_date', '<=', (string) $filters['date_to']);
        }

        return $q->orderByDesc('adjustment_date')->orderByDesc('id')->get();
    }

    public function find(int $id): StockAdjustment
    {
        return StockAdjustment::query()->with('lines.product', 'lines.warehouse', 'stockMovement')->findOrFail($id);
    }

    public function create(array $data): StockAdjustment
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);

        $date = (string) $data['adjustment_date'];
        $lines = (array) $data['lines'];
        $workflow = $this->companySettingService->getOrCreateAccountingSetting($company);
        $shouldAutoPost = $this->shouldAutoPostOnCreate($workflow);

        return DB::connection('tenant')->transaction(function () use ($company, $data, $date, $lines, $shouldAutoPost) {
            $adj = StockAdjustment::query()->create([
                'adjustment_number' => $this->documentNumberService->generate($company, DocumentType::STOCK_ADJUSTMENT, $date),
                'adjustment_date' => $date,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'status' => 'draft',
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            $adj->lines()->createMany($this->normalizeLines($lines));

            $this->auditLogService->logSuccess([
                'event' => 'inventory.stock_adjustment_created',
                'module' => 'inventory',
                'action' => 'stock_adjustment.create',
                'message' => 'Stock adjustment draft created.',
                'record_type' => 'stock_adjustment',
                'record_id' => $adj->id,
                'record_number' => $adj->adjustment_number,
            ], tenant: true);

            if ($shouldAutoPost) {
                return $this->post($adj->refresh()->load('lines'));
            }

            return $adj->refresh()->load('lines.product', 'lines.warehouse');
        });
    }

    public function update(StockAdjustment $adjustment, array $data): StockAdjustment
    {
        if ($adjustment->status !== 'draft') {
            throw ApiException::make('STOCK_ADJUSTMENT_NOT_EDITABLE', 'Stock adjustment is not editable.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($adjustment, $data) {
            $adjustment->fill([
                'adjustment_date' => $data['adjustment_date'] ?? $adjustment->adjustment_date,
                'warehouse_id' => $data['warehouse_id'] ?? $adjustment->warehouse_id,
                'reason' => $data['reason'] ?? $adjustment->reason,
                'notes' => $data['notes'] ?? $adjustment->notes,
                'internal_notes' => $data['internal_notes'] ?? $adjustment->internal_notes,
                'metadata' => $data['metadata'] ?? $adjustment->metadata,
                'updated_by' => auth()->id(),
                'revision_no' => (int) $adjustment->revision_no + 1,
            ])->save();

            if (array_key_exists('lines', $data)) {
                $adjustment->lines()->delete();
                $adjustment->lines()->createMany($this->normalizeLines((array) $data['lines']));
            }

            $this->auditLogService->logSuccess([
                'event' => 'inventory.stock_adjustment_updated',
                'module' => 'inventory',
                'action' => 'stock_adjustment.update',
                'message' => 'Stock adjustment draft updated.',
                'record_type' => 'stock_adjustment',
                'record_id' => $adjustment->id,
                'record_number' => $adjustment->adjustment_number,
            ], tenant: true);

            return $adjustment->refresh()->load('lines.product', 'lines.warehouse');
        });
    }

    public function approve(StockAdjustment $adjustment): StockAdjustment
    {
        if ($adjustment->status !== 'draft') {
            throw ApiException::make('INVALID_STOCK_ADJUSTMENT_STATUS', 'Invalid stock adjustment status transition.', 422);
        }

        $adjustment->status = 'approved';
        $adjustment->approved_by = auth()->id();
        $adjustment->approved_at = now();
        $adjustment->save();

        $this->auditLogService->logSuccess([
            'event' => 'inventory.stock_adjustment_approved',
            'module' => 'inventory',
            'action' => 'stock_adjustment.approve',
            'message' => 'Stock adjustment approved.',
            'record_type' => 'stock_adjustment',
            'record_id' => $adjustment->id,
            'record_number' => $adjustment->adjustment_number,
        ], tenant: true);

        if ($this->shouldAutoPostAfterApproval()) {
            return $this->post($adjustment->refresh()->load('lines'));
        }

        return $adjustment->refresh()->load('lines');
    }

    public function post(StockAdjustment $adjustment): StockAdjustment
    {
        $requiresApproval = $this->approvalRequired();
        $allowedStatuses = $requiresApproval ? ['approved'] : ['draft', 'approved'];

        if (! in_array((string) $adjustment->status, $allowedStatuses, true)) {
            throw ApiException::make('INVALID_STOCK_ADJUSTMENT_STATUS', 'Stock adjustment cannot be posted.', 422);
        }

        $adjustment->loadMissing('lines');
        if ($adjustment->lines->contains(fn ($l) => (string) $l->adjustment_type === 'increase')) {
            $this->assertMapping('inventory.adjustment_gain');
        }
        if ($adjustment->lines->contains(fn ($l) => (string) $l->adjustment_type !== 'increase')) {
            $this->assertMapping('inventory.adjustment_loss');
        }

        return DB::connection('tenant')->transaction(function () use ($adjustment) {
            $adjustment->loadMissing('lines');
            $this->movementValidation->validatePeriodNotLocked((string) $adjustment->adjustment_date);

            $movementIds = $this->createStockMovements($adjustment);
            $adjustment->stock_movement_id = $movementIds[0] ?? null;
            $adjustment->metadata = array_merge((array) ($adjustment->metadata ?? []), ['stock_movement_ids' => $movementIds]);
            $adjustment->status = 'posted';
            $adjustment->posted_by = auth()->id();
            $adjustment->posted_at = now();
            $adjustment->save();

            $this->auditLogService->logSuccess([
                'event' => 'inventory.stock_adjustment_posted',
                'module' => 'inventory',
                'action' => 'stock_adjustment.post',
                'message' => 'Stock adjustment posted.',
                'record_type' => 'stock_adjustment',
                'record_id' => $adjustment->id,
                'record_number' => $adjustment->adjustment_number,
                'metadata' => ['stock_movement_ids' => $movementIds],
            ], tenant: true);

            return $adjustment->refresh()->load('lines', 'stockMovement');
        });
    }

    private function approvalRequired(): bool
    {
        $company = $this->tenantContext->company();
        if (! $company) return true;

        $workflow = $this->companySettingService->getOrCreateAccountingSetting($company);

        return (bool) $workflow->approval_enabled || $workflow->transaction_workflow_mode === 'draft_approve_post';
    }

    private function shouldAutoPostOnCreate(object $workflow): bool
    {
        if ((bool) $workflow->approval_enabled) return false;

        return $workflow->transaction_workflow_mode === 'simple_auto_post'
            && (bool) $workflow->auto_post_transactions;
    }

    private function shouldAutoPostAfterApproval(): bool
    {
        $company = $this->tenantContext->company();
        if (! $company) return false;

        $workflow = $this->companySettingService->getOrCreateAccountingSetting($company);

        return (bool) $workflow->approval_enabled
            && (bool) $workflow->auto_post_transactions;
    }

    public function void(StockAdjustment $adjustment, ?string $reason = null): StockAdjustment
    {
        if ($adjustment->status === 'void') {
            throw ApiException::make('STOCK_ADJUSTMENT_ALREADY_VOID', 'Stock adjustment already void.', 422);
        }

        $reason = trim((string) $reason);
        if ($reason === '') throw ApiException::make('VALIDATION_ERROR', 'Void reason is required.', 422, ['reason' => ['Void reason is required.']]);
        $this->movementValidation->validatePeriodNotLocked((string) $adjustment->adjustment_date);

        return DB::connection('tenant')->transaction(function () use ($adjustment, $reason) {
            if ($adjustment->status === 'posted') {
                $ids = $this->movementIdsForAdjustment($adjustment);
                foreach ($ids as $mid) {
                    $m = StockMovement::query()->find($mid);
                    if ($m) {
                        $this->stockMovementService->void($m, $reason);
                    }
                }
            }

            $adjustment->status = 'void';
            $adjustment->voided_by = auth()->id();
            $adjustment->voided_at = now();
            $adjustment->void_reason = $reason;
            $adjustment->save();

            $this->auditLogService->logSuccess([
                'event' => 'inventory.stock_adjustment_voided',
                'module' => 'inventory',
                'action' => 'stock_adjustment.void',
                'message' => 'Stock adjustment voided.',
                'record_type' => 'stock_adjustment',
                'record_id' => $adjustment->id,
                'record_number' => $adjustment->adjustment_number,
                'metadata' => ['reason' => $reason],
            ], tenant: true);

            return $adjustment->refresh()->load('stockMovement');
        });
    }

    private function normalizeLines(array $lines): array
    {
        $out = [];
        $i = 0;
        foreach ($lines as $ln) {
            $product = Product::query()->findOrFail((int) $ln['product_id']);
            $this->movementValidation->validateProductIsStockable($product);
            $this->movementValidation->validateWarehouseExists((int) $ln['warehouse_id']);

            $qty = $this->qtyService->normalizeQuantity($ln['quantity']);
            $balance = $this->stockBalanceService->getOrCreateBalance((int) $ln['product_id'], (int) $ln['warehouse_id']);

            $out[] = [
                'product_id' => (int) $ln['product_id'],
                'warehouse_id' => (int) $ln['warehouse_id'],
                'unit_id' => $ln['unit_id'] ?? null,
                'adjustment_type' => (string) $ln['adjustment_type'],
                'quantity' => $qty,
                'unit_cost' => $ln['unit_cost'] ?? null,
                'total_cost' => $ln['total_cost'] ?? null,
                'system_quantity_before' => (float) $balance->quantity_on_hand,
                'system_value_before' => (float) $balance->total_value,
                'reason' => $ln['reason'] ?? null,
                'department_id' => $ln['department_id'] ?? null,
                'project_id' => $ln['project_id'] ?? null,
                'sort_order' => (int) ($ln['sort_order'] ?? $i++),
                'metadata' => $ln['metadata'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @return array<int>
     */
    private function createStockMovements(StockAdjustment $adjustment): array
    {
        $increaseLines = [];
        $decreaseLines = [];

        foreach ($adjustment->lines as $ln) {
            $bal = $this->stockBalanceService->getOrCreateBalance((int) $ln->product_id, (int) $ln->warehouse_id);
            $ln->system_quantity_before = (float) $bal->quantity_on_hand;
            $ln->system_value_before = (float) $bal->total_value;
            $ln->save();

            if ((string) $ln->adjustment_type === 'increase') {
                $unitCost = $ln->unit_cost;
                if ($unitCost === null) {
                    $policy = (string) config('inventory.adjustment_in_unit_cost_policy', 'fallback_average_cost');
                    if ($policy === 'require_unit_cost') {
                        throw ApiException::make('UNIT_COST_REQUIRED', 'Unit cost is required for adjustment increase.', 422);
                    }
                    $unitCost = (float) $bal->average_cost;
                }

                $increaseLines[] = [
                    'product_id' => (int) $ln->product_id,
                    'warehouse_id' => (int) $ln->warehouse_id,
                    'unit_id' => $ln->unit_id,
                    'quantity' => (float) $ln->quantity,
                    'unit_cost' => (float) $unitCost,
                    'department_id' => $ln->department_id,
                    'project_id' => $ln->project_id,
                    'source_line_type' => 'stock_adjustment_line',
                    'source_line_id' => (int) $ln->id,
                    'sort_order' => (int) ($ln->sort_order ?? 0),
                ];
            } else {
                $this->stockBalanceService->assertSufficientStock((int) $ln->product_id, (int) $ln->warehouse_id, (float) $ln->quantity);
                $decreaseLines[] = [
                    'product_id' => (int) $ln->product_id,
                    'warehouse_id' => (int) $ln->warehouse_id,
                    'unit_id' => $ln->unit_id,
                    'quantity' => (float) $ln->quantity,
                    'unit_cost' => 0,
                    'department_id' => $ln->department_id,
                    'project_id' => $ln->project_id,
                    'source_line_type' => 'stock_adjustment_line',
                    'source_line_id' => (int) $ln->id,
                    'sort_order' => (int) ($ln->sort_order ?? 0),
                ];
            }
        }

        $movementIds = [];

        if ($increaseLines !== []) {
            $m = $this->stockMovementService->createAndPost([
                'movement_date' => (string) $adjustment->adjustment_date,
                'movement_type' => 'adjustment_in',
                'source_type' => SourceType::STOCK_ADJUSTMENT->value,
                'source_id' => (int) $adjustment->id,
                'source_number' => $adjustment->adjustment_number,
                'source_revision' => (int) $adjustment->revision_no,
                'description' => 'Stock adjustment increase '.$adjustment->adjustment_number,
                'notes' => $adjustment->notes,
                'internal_notes' => $adjustment->internal_notes,
                'lines' => $increaseLines,
            ]);
            $movementIds[] = (int) $m->id;
        }

        if ($decreaseLines !== []) {
            $m = $this->stockMovementService->createAndPost([
                'movement_date' => (string) $adjustment->adjustment_date,
                'movement_type' => 'adjustment_out',
                'source_type' => SourceType::STOCK_ADJUSTMENT->value,
                'source_id' => (int) $adjustment->id,
                'source_number' => $adjustment->adjustment_number,
                'source_revision' => (int) $adjustment->revision_no,
                'description' => 'Stock adjustment decrease '.$adjustment->adjustment_number,
                'notes' => $adjustment->notes,
                'internal_notes' => $adjustment->internal_notes,
                'lines' => $decreaseLines,
            ]);
            $movementIds[] = (int) $m->id;
        }

        return $movementIds;
    }

    private function movementIdsForAdjustment(StockAdjustment $adjustment): array
    {
        $meta = (array) ($adjustment->metadata ?? []);
        $ids = $meta['stock_movement_ids'] ?? null;
        if (is_array($ids) && $ids !== []) return array_values(array_map('intval', $ids));
        return $adjustment->stock_movement_id ? [(int) $adjustment->stock_movement_id] : [];
    }

    private function assertMapping(string $key): void
    {
        $mapping = AccountMapping::query()->where('mapping_key', $key)->where('is_active', true)->first();
        if (! $mapping?->account_id) {
            throw ApiException::make(
                'MAPPING_REQUIRED',
                "Account mapping [{$key}] is required for this operation.",
                422,
                ['account_mapping' => ["{$key} is not configured"]]
            );
        }
    }
}
