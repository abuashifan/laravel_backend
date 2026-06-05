<?php

namespace App\Services\Inventory;

class InventorySourceService
{
    public function buildSourcePayload(?string $sourceType, ?int $sourceId, ?string $sourceNumber = null, ?int $sourceRevision = null): array
    {
        return [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_number' => $sourceNumber,
            'source_revision' => $sourceRevision,
        ];
    }

    public function buildSourceLinePayload(?string $sourceLineType, ?int $sourceLineId): array
    {
        return [
            'source_line_type' => $sourceLineType,
            'source_line_id' => $sourceLineId,
        ];
    }

    public function assertNoDuplicateSourceMovement(string $sourceType, int $sourceId, ?int $sourceLineId = null): void
    {
        // Phase 12A: stock movement table/engine is introduced in Phase 12B.
        // This method is kept as a placeholder so Phase 12B can enforce no double stock movement.
    }
}

