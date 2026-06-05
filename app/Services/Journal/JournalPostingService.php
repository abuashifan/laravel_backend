<?php

namespace App\Services\Journal;

use App\Exceptions\ApiException;
use App\Models\Tenant\JournalEntry;
use App\Support\Api\ApiErrorCode;
use Illuminate\Support\Facades\DB;

class JournalPostingService
{
    public function __construct(private readonly JournalValidationService $validator)
    {
    }

    public function assertCanPost(JournalEntry $journal): void
    {
        $can = $this->validator->validateCanPost($journal);
        if (! $can['valid']) {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Journal cannot be posted.', 422, $can['errors'] ?? []);
        }
    }

    public function post(JournalEntry $journal, ?int $userId = null): JournalEntry
    {
        $this->assertCanPost($journal);

        $lines = $journal->relationLoaded('lines') ? $journal->lines->toArray() : $journal->lines()->get()->toArray();
        $balanced = $this->validator->validateLines($this->mapLinesForValidation($lines), requireActiveAccounts: false);
        if (! $balanced['valid']) {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Journal must be balanced before posting.', 422, $balanced['errors'] ?? []);
        }

        return DB::transaction(function () use ($journal, $userId) {
            $journal->status = 'posted';
            $journal->posted_by = $userId;
            $journal->posted_at = now();
            $journal->save();

            return $journal->refresh();
        });
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    private function mapLinesForValidation(array $lines): array
    {
        return array_map(function ($line) {
            return [
                'account_id' => $line['account_id'] ?? null,
                'debit' => $line['debit'] ?? 0,
                'credit' => $line['credit'] ?? 0,
                'description' => $line['description'] ?? null,
                'line_order' => $line['line_order'] ?? null,
                'metadata' => $line['metadata'] ?? null,
            ];
        }, $lines);
    }
}

