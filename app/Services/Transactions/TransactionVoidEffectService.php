<?php

namespace App\Services\Transactions;

use App\Exceptions\ApiException;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\StockMovement;
use App\Services\Inventory\StockMovementService;
use App\Services\Audit\AuditLogService;

class TransactionVoidEffectService
{
    public function __construct(
        private readonly StockMovementService $stockMovementService,
        private readonly ?AuditLogService $auditLogService = null,
    )
    {
    }

    public function requireReason(?string $reason): string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ApiException::make('VALIDATION_ERROR', 'Void reason is required.', 422, [
                'reason' => ['Void reason is required.'],
            ]);
        }

        return $reason;
    }

    /**
     * Void system-generated accounting effects according to the existing
     * journal policy: posted lines stay immutable and the journal is excluded
     * from reportable effects by moving its lifecycle status to void.
     *
     * @return array<int>
     */
    public function voidJournalsForSource(string $sourceType, int $sourceId, string $reason): array
    {
        $ids = [];
        $journals = JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('is_system_generated', true)
            ->get();

        foreach ($journals as $journal) {
            $this->voidJournal($journal, $reason);
            $ids[] = (int) $journal->id;
        }

        return $ids;
    }

    public function voidJournalById(?int $journalId, string $reason): ?int
    {
        if (! $journalId) {
            return null;
        }

        $journal = JournalEntry::query()->find($journalId);
        if (! $journal) {
            return null;
        }

        $this->voidJournal($journal, $reason);

        return (int) $journal->id;
    }

    /**
     * @return array<int>
     */
    public function voidStockMovementsForSource(string $sourceType, int $sourceId, string $reason): array
    {
        $ids = [];
        $movements = StockMovement::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereIn('status', ['draft', 'posted'])
            ->get();

        foreach ($movements as $movement) {
            $this->stockMovementService->void($movement, $reason);
            $ids[] = (int) $movement->id;
        }

        return $ids;
    }

    private function voidJournal(JournalEntry $journal, string $reason): void
    {
        if ($journal->status === 'void') {
            return;
        }

        if (! $journal->is_system_generated) {
            throw ApiException::make('SYSTEM_GENERATED_READ_ONLY', 'Only generated journals may be cascaded from a source transaction.', 422);
        }

        $journal->status = 'void';
        $journal->void_reason = $reason;
        $journal->voided_by = auth()->id();
        $journal->voided_at = now();
        $journal->save();

        $this->auditLogService?->logSuccess([
            'event' => 'journal.generated_effect_voided',
            'module' => $journal->source_module ?? 'journal',
            'action' => 'generated_journal.void',
            'message' => 'Generated journal voided by source transaction cascade.',
            'record_type' => 'journal_entry',
            'record_id' => (string) $journal->id,
            'record_number' => $journal->journal_number,
            'user_id' => auth()->id(),
            'source_type' => $journal->source_type,
            'source_id' => $journal->source_id,
            'source_number' => $journal->source_number,
            'source_revision' => $journal->source_revision,
            'metadata' => ['reason' => $reason],
        ], tenant: true);
    }
}
