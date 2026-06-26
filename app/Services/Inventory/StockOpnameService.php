<?php

namespace App\Services\Inventory;

use App\Enums\SourceType;
use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\StockOpname;
use App\Models\Tenant\StockOpnameLine;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Tenant\TenantContext;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class StockOpnameService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly StockMovementService $stockMovementService,
        private readonly StockBalanceService $stockBalanceService,
        private readonly InventoryQuantityService $qtyService,
        private readonly StockMovementValidationService $movementValidation,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function list(array $filters = []): Collection
    {
        $q = StockOpname::query()->with('warehouse')->withCount('lines');

        if (! empty($filters['status'])) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $filters['status']))));
            if ($statuses !== []) {
                $q->whereIn('status', $statuses);
            }
        }

        if (! empty($filters['warehouse_id'])) $q->where('warehouse_id', (int) $filters['warehouse_id']);

        if (! empty($filters['date_from'])) {
            $q->whereDate('opname_date', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $q->whereDate('opname_date', '<=', (string) $filters['date_to']);
        }

        return $q->orderByDesc('opname_date')->orderByDesc('id')->get();
    }

    public function find(int $id): StockOpname
    {
        return StockOpname::query()->with('lines.product', 'warehouse', 'stockMovement')->findOrFail($id);
    }

    public function createSession(array $data): StockOpname
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);

        $this->movementValidation->validateWarehouseExists((int) $data['warehouse_id']);

        return DB::connection('tenant')->transaction(function () use ($company, $data) {
            $opname = StockOpname::query()->create([
                'opname_number' => $this->documentNumberService->generate($company, DocumentType::STOCK_OPNAME, (string) $data['opname_date']),
                'opname_date' => (string) $data['opname_date'],
                'warehouse_id' => (int) $data['warehouse_id'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            $this->auditLogService->logSuccess([
                'event' => 'inventory.stock_opname_created',
                'module' => 'inventory',
                'action' => 'stock_opname.create',
                'message' => 'Stock opname session created.',
                'record_type' => 'stock_opname',
                'record_id' => $opname->id,
                'record_number' => $opname->opname_number,
            ], tenant: true);

            return $opname->refresh()->load('warehouse');
        });
    }

    public function generateLinesFromStockBalance(StockOpname $opname): StockOpname
    {
        if ($opname->status !== 'draft') {
            throw ApiException::make('STOCK_OPNAME_NOT_EDITABLE', 'Stock opname cannot generate lines in this status.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($opname) {
            $opname->lines()->delete();

            $balances = StockBalance::query()
                ->where('warehouse_id', (int) $opname->warehouse_id)
                ->with('product')
                ->orderBy('product_id')
                ->get();

            $lines = [];
            $order = 0;
            foreach ($balances as $b) {
                $p = $b->product;
                if (! $p) continue;
                if (! (bool) $p->is_stock_item) continue;

                $lines[] = [
                    'product_id' => (int) $b->product_id,
                    'warehouse_id' => (int) $b->warehouse_id,
                    'unit_id' => $p->unit_id,
                    'system_quantity' => (float) $b->quantity_on_hand,
                    'physical_quantity' => null,
                    'difference_quantity' => 0,
                    'average_cost' => (float) $b->average_cost,
                    'difference_value' => 0,
                    'sort_order' => $order++,
                ];
            }

            $opname->lines()->createMany($lines);

            $this->auditLogService->logSuccess([
                'event' => 'inventory.stock_opname_lines_generated',
                'module' => 'inventory',
                'action' => 'stock_opname.generate_lines',
                'message' => 'Stock opname lines generated from stock balance.',
                'record_type' => 'stock_opname',
                'record_id' => $opname->id,
                'record_number' => $opname->opname_number,
            ], tenant: true);

            return $opname->refresh()->load('lines.product', 'warehouse');
        });
    }

    public function updateLineCount(StockOpnameLine $line, array $data): StockOpnameLine
    {
        $line->loadMissing('opname');
        $opname = $line->opname;
        if (! $opname || in_array($opname->status, ['finalized', 'void'], true)) {
            throw ApiException::make('STOCK_OPNAME_NOT_EDITABLE', 'Stock opname line is not editable.', 422);
        }

        $physical = $this->qtyService->normalizeQuantity($data['physical_quantity']);
        $diff = $physical - (float) $line->system_quantity;
        $diffValue = round($diff * (float) $line->average_cost, (int) config('inventory.amount_precision', 2));

        $line->physical_quantity = $physical;
        $line->difference_quantity = round($diff, (int) config('inventory.stock_precision', 4));
        $line->difference_value = $diffValue;
        $line->reason = $data['reason'] ?? $line->reason;
        $line->counted_by = auth()->id();
        $line->counted_at = now();
        $line->save();

        return $line->refresh();
    }

    public function markCounted(StockOpname $opname): StockOpname
    {
        if (! in_array($opname->status, ['draft', 'counted'], true)) {
            throw ApiException::make('INVALID_STOCK_OPNAME_STATUS', 'Stock opname cannot be marked counted.', 422);
        }

        $opname->status = 'counted';
        $opname->counted_by = auth()->id();
        $opname->counted_at = now();
        $opname->save();

        $this->auditLogService->logSuccess([
            'event' => 'inventory.stock_opname_counted',
            'module' => 'inventory',
            'action' => 'stock_opname.counted',
            'message' => 'Stock opname marked as counted.',
            'record_type' => 'stock_opname',
            'record_id' => $opname->id,
            'record_number' => $opname->opname_number,
        ], tenant: true);

        return $opname->refresh()->load('lines');
    }

    public function finalize(StockOpname $opname): StockOpname
    {
        if ($opname->status !== 'counted') {
            throw ApiException::make('INVALID_STOCK_OPNAME_STATUS', 'Stock opname cannot be finalized.', 422);
        }

        $opname->loadMissing('lines');
        if ($opname->lines->contains(fn ($l) => (float) $l->difference_quantity > 1e-9)) {
            $this->assertMapping('inventory.adjustment_gain');
        }
        if ($opname->lines->contains(fn ($l) => (float) $l->difference_quantity < -1e-9)) {
            $this->assertMapping('inventory.adjustment_loss');
        }

        return DB::connection('tenant')->transaction(function () use ($opname) {
            $opname->loadMissing('lines');
            $this->movementValidation->validatePeriodNotLocked((string) $opname->opname_date);

            if (! (bool) config('inventory.opname_allow_partial_count', false)) {
                $missing = $opname->lines->first(fn ($ln) => $ln->physical_quantity === null);
                if ($missing) {
                    throw ApiException::make('STOCK_OPNAME_INCOMPLETE', 'All stock opname lines must be counted before finalize.', 422);
                }
            }

            $movementIds = $this->createStockMovementsFromDifferences($opname);
            $opname->stock_movement_id = $movementIds[0] ?? null;
            $opname->metadata = array_merge((array) ($opname->metadata ?? []), ['stock_movement_ids' => $movementIds]);
            $opname->status = 'finalized';
            $opname->finalized_by = auth()->id();
            $opname->finalized_at = now();
            $opname->save();

            $this->auditLogService->logSuccess([
                'event' => 'inventory.stock_opname_finalized',
                'module' => 'inventory',
                'action' => 'stock_opname.finalize',
                'message' => 'Stock opname finalized.',
                'record_type' => 'stock_opname',
                'record_id' => $opname->id,
                'record_number' => $opname->opname_number,
                'metadata' => ['stock_movement_ids' => $movementIds],
            ], tenant: true);

            return $opname->refresh()->load('lines', 'stockMovement');
        });
    }

    public function void(StockOpname $opname, ?string $reason = null): StockOpname
    {
        if ($opname->status === 'void') return $opname;
        $reason = trim((string) $reason);
        if ($reason === '') throw ApiException::make('VALIDATION_ERROR', 'Void reason is required.', 422, ['reason' => ['Void reason is required.']]);
        $this->movementValidation->validatePeriodNotLocked((string) $opname->opname_date);

        return DB::connection('tenant')->transaction(function () use ($opname, $reason) {
            if ($opname->status === 'finalized') {
                $ids = $this->movementIdsForOpname($opname);
                foreach ($ids as $mid) {
                    $m = StockMovement::query()->find($mid);
                    if ($m) $this->stockMovementService->void($m, $reason);
                }
            }

            $opname->status = 'void';
            $opname->voided_by = auth()->id();
            $opname->voided_at = now();
            $opname->void_reason = $reason;
            $opname->save();

            $this->auditLogService->logSuccess([
                'event' => 'inventory.stock_opname_voided',
                'module' => 'inventory',
                'action' => 'stock_opname.void',
                'message' => 'Stock opname voided.',
                'record_type' => 'stock_opname',
                'record_id' => $opname->id,
                'record_number' => $opname->opname_number,
                'metadata' => ['reason' => $reason],
            ], tenant: true);

            return $opname->refresh();
        });
    }

    /**
     * @return array<int>
     */
    private function createStockMovementsFromDifferences(StockOpname $opname): array
    {
        $inLines = [];
        $outLines = [];

        foreach ($opname->lines as $ln) {
            $diff = (float) $ln->difference_quantity;
            if (abs($diff) < 1e-9) continue;

            // For outgoing, StockBalanceService will compute cost using average cost before movement.
            if ($diff > 0) {
                $inLines[] = [
                    'product_id' => (int) $ln->product_id,
                    'warehouse_id' => (int) $ln->warehouse_id,
                    'unit_id' => (int) ($ln->unit_id ?? null),
                    'quantity' => $diff,
                    'unit_cost' => (float) $ln->average_cost,
                    'source_line_type' => 'stock_opname_line',
                    'source_line_id' => (int) $ln->id,
                    'sort_order' => (int) ($ln->sort_order ?? 0),
                ];
            } else {
                $qtyOut = abs($diff);
                $this->stockBalanceService->assertSufficientStock((int) $ln->product_id, (int) $ln->warehouse_id, $qtyOut);
                $outLines[] = [
                    'product_id' => (int) $ln->product_id,
                    'warehouse_id' => (int) $ln->warehouse_id,
                    'unit_id' => (int) ($ln->unit_id ?? null),
                    'quantity' => $qtyOut,
                    'unit_cost' => 0,
                    'source_line_type' => 'stock_opname_line',
                    'source_line_id' => (int) $ln->id,
                    'sort_order' => (int) ($ln->sort_order ?? 0),
                ];
            }
        }

        $ids = [];
        if ($inLines !== []) {
            $m = $this->stockMovementService->createAndPost([
                'movement_date' => (string) $opname->opname_date,
                'movement_type' => 'opname_in',
                'source_type' => SourceType::STOCK_OPNAME->value,
                'source_id' => (int) $opname->id,
                'source_number' => $opname->opname_number,
                'source_revision' => 1,
                'description' => 'Stock opname increase '.$opname->opname_number,
                'lines' => $inLines,
            ]);
            $ids[] = (int) $m->id;
        }
        if ($outLines !== []) {
            $m = $this->stockMovementService->createAndPost([
                'movement_date' => (string) $opname->opname_date,
                'movement_type' => 'opname_out',
                'source_type' => SourceType::STOCK_OPNAME->value,
                'source_id' => (int) $opname->id,
                'source_number' => $opname->opname_number,
                'source_revision' => 1,
                'description' => 'Stock opname decrease '.$opname->opname_number,
                'lines' => $outLines,
            ]);
            $ids[] = (int) $m->id;
        }

        return $ids;
    }

    private function movementIdsForOpname(StockOpname $opname): array
    {
        $meta = (array) ($opname->metadata ?? []);
        $ids = $meta['stock_movement_ids'] ?? null;
        if (is_array($ids) && $ids !== []) return array_values(array_map('intval', $ids));
        return $opname->stock_movement_id ? [(int) $opname->stock_movement_id] : [];
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
