<?php

namespace App\Services\Purchase;

class PurchaseSourceChainService
{
    public function buildSourcePayload(
        ?string $sourceType,
        ?int $sourceId,
        ?string $sourceNumber = null,
        ?int $sourceRevision = null
    ): array {
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
}
