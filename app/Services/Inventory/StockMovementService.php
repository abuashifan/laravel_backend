<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Tenant\TenantContext;
use App\Support\DocumentNumbering\DocumentType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class StockMovementService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly StockMovementValidationService $validation,
        private readonly InventoryQuantityService $qtyService,
        private readonly InventorySourceService $sourceService,
        private readonly StockMovementJournalService $journalService,
        private readonly StockBalanceService $stockBalanceService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function list(array $filters = []): Collection
    {
        $query = StockMovement::query();
        if (! empty($filters['status'])) $query->where('status', (string) $filters['status']);
        if (! empty($filters['movement_type'])) $query->where('movement_type', (string) $filters['movement_type']);
        return $query->orderByDesc('movement_date')->orderByDesc('id')->get();
    }

    public function find(int $id): StockMovement
    {
        return StockMovement::query()->with('lines', 'journalEntry', 'reversalOf', 'reversedBy')->findOrFail($id);
    }

    public function createDraft(array $data): StockMovement
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);

        $this->validation->validateMovementType((string) $data['movement_type']);
        $this->validation->validateLines((array) $data['lines']);

        $movementDate = Carbon::parse((string) $data['movement_date'])->toDateString();
        $movementType = (string) $data['movement_type'];
        $direction = $this->validation->directionForType($movementType);

        $this->validateSourceNotAlreadyMoved($data);

        return DB::connection('tenant')->transaction(function () use ($company, $data, $movementDate, $movementType, $direction) {
            $header = $data;
            $lines = (array) ($header['lines'] ?? []);
            unset($header['lines']);

            $totals = $this->computeTotals($lines);

            $movement = StockMovement::query()->create(array_merge($header, [
                'movement_number' => $this->documentNumberService->generate($company, DocumentType::STOCK_MOVEMENT, $movementDate),
                'movement_date' => $movementDate,
                'movement_type' => $movementType,
                'direction' => $direction,
                'status' => 'draft',
                'total_quantity' => $totals['total_quantity'],
                'total_value' => $totals['total_value'],
                'created_by' => auth()->id(),
            ]));

            $order = 0;
            foreach ($lines as $ln) {
                $product = Product::query()->findOrFail((int) $ln['product_id']);
                $this->validation->validateProductIsStockable($product);
                $this->validation->validateWarehouseExists((int) $ln['warehouse_id']);

                $qty = $this->qtyService->normalizeQuantity($ln['quantity']);
                $unitCost = (float) ($ln['unit_cost'] ?? 0);
                $totalCost = round($qty * $unitCost, (int) config('inventory.amount_precision', 2));

                $movement->lines()->create(array_merge($ln, [
                    'movement_type' => $movementType,
                    'direction' => $direction,
                    'product_code' => $product->product_code,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'sort_order' => (int) ($ln['sort_order'] ?? $order++),
                ]));
            }

            $movement->refresh()->load('lines');

            $this->auditLogService->logSuccess([
                'event' => 'inventory.stock_movement_draft_created',
                'module' => 'inventory',
                'action' => 'stock_movement.create_draft',
                'message' => 'Stock movement draft created.',
                'record_type' => 'stock_movement',
                'record_id' => $movement->id,
                'record_number' => $movement->movement_number,
                'source_type' => $movement->source_type,
                'source_id' => $movement->source_id,
                'source_number' => $movement->source_number,
                'source_revision' => $movement->source_revision,
            ], tenant: true);

            return $movement;
        });
    }

    public function post(StockMovement $movement): StockMovement
    {
        if ($movement->status === 'posted') return $movement;

        $this->validation->validatePeriodNotLocked((string) $movement->movement_date);

        return DB::connection('tenant')->transaction(function () use ($movement) {
            $movement->loadMissing('lines');
            $this->validation->validateLines($movement->lines->toArray());

            $totalQty = 0.0;
            $totalValue = 0.0;
            foreach ($movement->lines as $ln) {
                $ln->setRelation('stockMovement', $movement);
                $this->stockBalanceService->applyMovementLine($ln);
                $totalQty += (float) $ln->quantity;
                $totalValue += (float) $ln->total_cost;
            }

            $movement->total_quantity = $totalQty;
            $movement->total_value = round($totalValue, (int) config('inventory.amount_precision', 2));
            $movement->save();

            $journal = $this->journalService->createInventoryJournalForMovement($movement);
            if ($journal) {
                $movement->journal_entry_id = $journal->id;
            }

            $movement->status = 'posted';
            $movement->posted_by = auth()->id();
            $movement->posted_at = now();
            $movement->save();

            $this->auditLogService->logSuccess([
                'event' => 'inventory.stock_movement_posted',
                'module' => 'inventory',
                'action' => 'stock_movement.post',
                'message' => 'Stock movement posted.',
                'record_type' => 'stock_movement',
                'record_id' => $movement->id,
                'record_number' => $movement->movement_number,
            ], tenant: true);

            return $movement->refresh()->load('lines', 'journalEntry');
        });
    }

    public function void(StockMovement $movement, ?string $reason = null): StockMovement
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ApiException::make('VALIDATION_ERROR', 'Void reason is required.', 422, ['reason' => ['Void reason is required.']]);
        }
        $this->validation->validatePeriodNotLocked((string) $movement->movement_date);

        if ($movement->status === 'void') {
            return $movement;
        }

        if ($movement->status !== 'posted') {
            $movement->status = 'void';
            $movement->voided_by = auth()->id();
            $movement->voided_at = now();
            $movement->void_reason = $reason;
            $movement->save();
            $this->auditLogService->logSuccess([
                'event' => 'inventory.stock_movement_voided',
                'module' => 'inventory',
                'action' => 'stock_movement.void',
                'message' => 'Stock movement voided.',
                'record_type' => 'stock_movement',
                'record_id' => $movement->id,
                'record_number' => $movement->movement_number,
                'metadata' => ['reason' => $reason],
            ], tenant: true);
            return $movement->refresh();
        }

        return DB::connection('tenant')->transaction(function () use ($movement, $reason) {
            $reversal = $this->createReversal($movement, $reason);
            $movement->status = 'void';
            $movement->voided_by = auth()->id();
            $movement->voided_at = now();
            $movement->void_reason = $reason;
            $movement->reversed_by_id = $reversal->id;
            $movement->save();

            $this->auditLogService->logSuccess([
                'event' => 'inventory.stock_movement_voided',
                'module' => 'inventory',
                'action' => 'stock_movement.void',
                'message' => 'Stock movement voided.',
                'record_type' => 'stock_movement',
                'record_id' => $movement->id,
                'record_number' => $movement->movement_number,
                'metadata' => ['reason' => $reason, 'reversal_id' => $reversal->id],
            ], tenant: true);

            return $movement->refresh()->load('reversedBy');
        });
    }

    public function createAndPost(array $data): StockMovement
    {
        $draft = $this->createDraft($data);
        return $this->post($draft);
    }

    public function createReversal(StockMovement $movement, ?string $reason = null): StockMovement
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);

        $movement->loadMissing('lines');
        $movementType = (string) $movement->movement_type;
        $direction = (string) $movement->direction;
        $reversalDirection = $direction === 'in' ? 'out' : 'in';

        $linesPayload = [];
        foreach ($movement->lines as $ln) {
            $linesPayload[] = [
                'product_id' => (int) $ln->product_id,
                'warehouse_id' => (int) $ln->warehouse_id,
                'unit_id' => $ln->unit_id,
                'quantity' => (float) $ln->quantity,
                'unit_cost' => (float) $ln->unit_cost,
                'department_id' => $ln->department_id,
                'project_id' => $ln->project_id,
                'source_line_type' => 'reversal_of_stock_movement_line',
                'source_line_id' => (int) $ln->id,
                'sort_order' => (int) ($ln->sort_order ?? 0),
            ];
        }

        $reversal = StockMovement::query()->create([
            'movement_number' => $this->documentNumberService->generate($company, DocumentType::STOCK_MOVEMENT, (string) $movement->movement_date),
            'movement_date' => $movement->movement_date,
            'movement_type' => $movementType,
            'direction' => $reversalDirection,
            'status' => 'draft',
            'source_type' => 'reversal',
            'source_id' => $movement->id,
            'source_number' => $movement->movement_number,
            'source_revision' => $movement->revision_no,
            'description' => 'Reversal of '.$movement->movement_number,
            'notes' => $reason,
            'total_quantity' => (float) $movement->total_quantity,
            'total_value' => (float) $movement->total_value,
            'reversal_of_id' => $movement->id,
            'created_by' => auth()->id(),
            'metadata' => ['is_reversal' => true],
        ]);

        $order = 0;
        foreach ($linesPayload as $ln) {
            $product = Product::query()->findOrFail((int) $ln['product_id']);
            $qty = $this->qtyService->normalizeQuantity($ln['quantity']);
            $unitCost = (float) ($ln['unit_cost'] ?? 0);
            $totalCost = round($qty * $unitCost, (int) config('inventory.amount_precision', 2));
            $reversal->lines()->create(array_merge($ln, [
                'movement_type' => $movementType,
                'direction' => $reversalDirection,
                'product_code' => $product->product_code,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'sort_order' => (int) ($ln['sort_order'] ?? $order++),
            ]));
        }

        return $this->post($reversal);
    }

    public function assertSourceNotAlreadyMoved(string $sourceType, int $sourceId, ?int $sourceLineId = null): void
    {
        $q = StockMovement::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereIn('status', ['draft', 'posted']);

        if ($q->exists()) {
            throw ApiException::make('DUPLICATE_SOURCE_MOVEMENT', 'Source already has stock movement.', 422);
        }
    }

    private function validateSourceNotAlreadyMoved(array $data): void
    {
        $sourceType = $data['source_type'] ?? null;
        $sourceId = $data['source_id'] ?? null;
        if ($sourceType && $sourceId) {
            $this->assertSourceNotAlreadyMoved((string) $sourceType, (int) $sourceId);
        }
    }

    private function computeTotals(array $lines): array
    {
        $qty = 0.0;
        $value = 0.0;
        foreach ($lines as $ln) {
            $q = $this->qtyService->normalizeQuantity($ln['quantity'] ?? 0);
            $qty += $q;
            $unitCost = (float) ($ln['unit_cost'] ?? 0);
            $value += round($q * $unitCost, (int) config('inventory.amount_precision', 2));
        }

        return [
            'total_quantity' => $qty,
            'total_value' => $value,
        ];
    }
}
