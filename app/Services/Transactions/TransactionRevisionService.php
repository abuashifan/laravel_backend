<?php

namespace App\Services\Transactions;

use App\Models\Tenant\TransactionRevision;
use App\Support\Revision\RevisionSnapshot;
use App\Support\Revision\TransactionRevisionAction;
use InvalidArgumentException;

class TransactionRevisionService
{
    public function currentRevisionNumber(mixed $transaction): int
    {
        if ($transaction === null) {
            return 1;
        }

        if (is_array($transaction)) {
            return isset($transaction['revision_no']) ? max(1, (int) $transaction['revision_no']) : 1;
        }

        if (is_object($transaction)) {
            if (isset($transaction->revision_no)) {
                return max(1, (int) $transaction->revision_no);
            }
            if (method_exists($transaction, 'getAttribute')) {
                $val = $transaction->getAttribute('revision_no');
                return $val !== null ? max(1, (int) $val) : 1;
            }
        }

        return 1;
    }

    public function nextRevisionNumber(mixed $transaction): int
    {
        return $this->currentRevisionNumber($transaction) + 1;
    }

    public function captureSnapshot(mixed $transaction, array $only = [], array $except = []): array
    {
        return RevisionSnapshot::from($transaction, $only, $except);
    }

    public function recordEdit(
        string $sourceType,
        int|string|null $sourceId,
        ?string $sourceNumber,
        ?string $sourceModule,
        ?int $revisionFrom,
        ?int $revisionTo,
        array $oldValues,
        array $newValues,
        ?string $reason = null,
        ?int $editedBy = null,
        array $metadata = []
    ): TransactionRevision {
        return $this->record(
            TransactionRevisionAction::EDIT,
            $sourceType,
            $sourceId,
            $sourceNumber,
            $sourceModule,
            $revisionFrom,
            $revisionTo,
            $oldValues,
            $newValues,
            $reason,
            $editedBy,
            $metadata
        );
    }

    public function recordVoid(
        string $sourceType,
        int|string|null $sourceId,
        ?string $sourceNumber,
        ?string $sourceModule,
        ?int $revision,
        ?string $reason = null,
        ?int $editedBy = null,
        array $oldValues = [],
        array $metadata = []
    ): TransactionRevision {
        return $this->record(
            TransactionRevisionAction::VOID,
            $sourceType,
            $sourceId,
            $sourceNumber,
            $sourceModule,
            $revision,
            $revision,
            $oldValues,
            [],
            $reason,
            $editedBy,
            $metadata
        );
    }

    public function record(
        string $action,
        string $sourceType,
        int|string|null $sourceId,
        ?string $sourceNumber,
        ?string $sourceModule,
        ?int $revisionFrom,
        ?int $revisionTo,
        array $oldValues = [],
        array $newValues = [],
        ?string $reason = null,
        ?int $editedBy = null,
        array $metadata = []
    ): TransactionRevision {
        if (! TransactionRevisionAction::exists($action)) {
            throw new InvalidArgumentException('UNKNOWN_REVISION_ACTION');
        }

        $diff = RevisionSnapshot::diff($oldValues, $newValues);

        return TransactionRevision::query()->create([
            'source_type' => $sourceType,
            'source_id' => $sourceId !== null ? (string) $sourceId : null,
            'source_number' => $sourceNumber,
            'source_module' => $sourceModule,
            'source_revision_from' => $revisionFrom,
            'source_revision_to' => $revisionTo,
            'action' => $action,
            'reason' => $reason,
            'old_values' => $diff['old_values'] ?? $oldValues,
            'new_values' => $diff['new_values'] ?? $newValues,
            'changed_fields' => $diff['changed_fields'] ?? [],
            'edited_by' => $editedBy,
            'edited_at' => now(),
            'metadata' => $metadata,
        ]);
    }
}

