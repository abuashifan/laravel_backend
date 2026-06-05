<?php

namespace App\Services\Journal;

use App\Exceptions\ApiException;
use App\Models\Tenant\JournalEntry;
use App\Support\Api\ApiErrorCode;
use Illuminate\Support\Facades\DB;

class JournalVoidService
{
    public function assertCanVoid(JournalEntry $journal): void
    {
        if ($journal->isVoided()) {
            throw ApiException::make(ApiErrorCode::TRANSACTION_ALREADY_VOID, 'Journal is already void.', 422);
        }

        if ($journal->isSystemGenerated()) {
            throw ApiException::make(ApiErrorCode::SYSTEM_GENERATED_READ_ONLY, 'System-generated journal cannot be voided directly.', 422);
        }
    }

    public function void(JournalEntry $journal, string $reason, ?int $userId = null): JournalEntry
    {
        $this->assertCanVoid($journal);

        return DB::transaction(function () use ($journal, $reason, $userId) {
            $journal->status = 'void';
            $journal->void_reason = $reason;
            $journal->voided_by = $userId;
            $journal->voided_at = now();
            $journal->save();

            return $journal->refresh();
        });
    }
}
