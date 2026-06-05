<?php

namespace App\Services\Inventory;

use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovementLine;

class AverageCostService
{
    public function calculateIncomingAverageCost(float $qtyBefore, float $valueBefore, float $incomingQty, float $incomingUnitCost): array
    {
        $incomingValue = round($incomingQty * $incomingUnitCost, (int) config('inventory.amount_precision', 2));
        $qtyAfter = $qtyBefore + $incomingQty;
        $valueAfter = $valueBefore + $incomingValue;

        $avgAfter = $qtyAfter == 0.0
            ? 0.0
            : round($valueAfter / $qtyAfter, (int) config('inventory.cost_precision', 6));

        return [
            'incoming_value' => $incomingValue,
            'quantity_after' => round($qtyAfter, (int) config('inventory.stock_precision', 4)),
            'value_after' => round($valueAfter, (int) config('inventory.amount_precision', 2)),
            'average_cost_after' => $avgAfter,
        ];
    }

    public function calculateOutgoingCost(float $currentAverageCost, float $outgoingQty): array
    {
        $cost = round($outgoingQty * $currentAverageCost, (int) config('inventory.amount_precision', 2));
        return ['outgoing_value' => $cost];
    }

    public function applyIncoming(StockBalance $balance, StockMovementLine $line): StockBalance
    {
        $qtyBefore = (float) $balance->quantity_on_hand;
        $valueBefore = (float) $balance->total_value;
        $avgBefore = (float) $balance->average_cost;

        $incomingQty = (float) $line->quantity;
        $incomingUnitCost = (float) ($line->unit_cost ?? 0);

        $calc = $this->calculateIncomingAverageCost($qtyBefore, $valueBefore, $incomingQty, $incomingUnitCost);

        $balance->quantity_on_hand = $calc['quantity_after'];
        $balance->total_value = $calc['value_after'];
        $balance->average_cost = $calc['average_cost_after'];
        $balance->recalculateAvailable();

        $line->quantity_before = $qtyBefore;
        $line->quantity_after = (float) $balance->quantity_on_hand;
        $line->average_cost_before = $avgBefore;
        $line->average_cost_after = (float) $balance->average_cost;
        $line->value_before = $valueBefore;
        $line->value_after = (float) $balance->total_value;
        $line->total_cost = round($incomingQty * $incomingUnitCost, (int) config('inventory.amount_precision', 2));

        return $balance;
    }

    public function applyOutgoing(StockBalance $balance, StockMovementLine $line): StockBalance
    {
        $qtyBefore = (float) $balance->quantity_on_hand;
        $valueBefore = (float) $balance->total_value;
        $avgBefore = (float) $balance->average_cost;

        $outgoingQty = (float) $line->quantity;
        $calc = $this->calculateOutgoingCost($avgBefore, $outgoingQty);
        $outgoingValue = (float) $calc['outgoing_value'];

        $qtyAfter = $qtyBefore - $outgoingQty;
        $valueAfter = $valueBefore - $outgoingValue;

        $balance->quantity_on_hand = round($qtyAfter, (int) config('inventory.stock_precision', 4));
        $balance->total_value = round($valueAfter, (int) config('inventory.amount_precision', 2));
        $balance->average_cost = $balance->quantity_on_hand == 0.0
            ? 0.0
            : round(((float) $balance->total_value) / (float) $balance->quantity_on_hand, (int) config('inventory.cost_precision', 6));
        $balance->recalculateAvailable();

        $line->quantity_before = $qtyBefore;
        $line->quantity_after = (float) $balance->quantity_on_hand;
        $line->average_cost_before = $avgBefore;
        $line->average_cost_after = (float) $balance->average_cost;
        $line->value_before = $valueBefore;
        $line->value_after = (float) $balance->total_value;

        // OUT movements are valued at current average cost before movement.
        $line->unit_cost = $avgBefore;
        $line->total_cost = $outgoingValue;

        return $balance;
    }

    public function resolveUnitCostForReturn(StockMovementLine $line): float
    {
        // Prefer cost captured from the original movement line if source_line_id points to it.
        $sourceLineId = (int) ($line->source_line_id ?? 0);
        if ($sourceLineId > 0) {
            $orig = StockMovementLine::query()->where('source_line_id', $sourceLineId)->orderByDesc('id')->first();
            if ($orig) {
                $cost = (float) ($orig->average_cost_before ?? 0);
                if ($cost > 0) return $cost;
            }
        }

        return (float) ($line->unit_cost ?? 0);
    }
}

