<?php

declare(strict_types=1);

namespace App\Services\Journal;

use App\Exceptions\ApiException;
use App\Models\Tenant\JournalEntry;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Tenant\TenantContext;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Support\Facades\DB;

class SystemJournalBuilder
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly JournalValidationService $validationService,
    ) {}

    /**
     * @param array{source_type:string,source_id:int|string,journal_date:string,description:string,source_number?:string,source_revision?:int,source_module?:string} $data
     * @param array<array{account_id:int,debit:float,credit:float,description?:string,line_order?:int}> $lines
     */
    public function create(array $data, array $lines): JournalEntry
    {
        $validation = $this->validationService->validateLines($lines);

        if (! $validation['valid']) {
            $errors = $validation['errors'];
            $errorKeys = array_keys($errors);

            $hasMissingAccount = collect($errorKeys)->contains(
                fn ($k) => str_contains((string) $k, 'account_id')
            );

            if ($hasMissingAccount) {
                throw ApiException::make('JOURNAL_ACCOUNT_MISSING', 'Journal line is missing account_id.', 422, $errors);
            }

            if (isset($errors['balance'])) {
                throw ApiException::make('JOURNAL_NOT_BALANCED', 'Journal lines are not balanced.', 422, $errors);
            }

            throw ApiException::make('JOURNAL_INVALID', 'Journal validation failed.', 422, $errors);
        }

        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($company, $data, $lines) {
            $journal = JournalEntry::query()->create([
                'journal_number'   => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, (string) $data['journal_date']),
                'journal_date'     => $data['journal_date'],
                'description'      => $data['description'],
                'status'           => 'posted',
                'revision_no'      => $data['revision_no'] ?? 1,
                'source_type'      => $data['source_type'],
                'source_id'        => $data['source_id'],
                'source_number'    => $data['source_number'] ?? null,
                'source_revision'  => $data['source_revision'] ?? 1,
                'source_module'    => $data['source_module'] ?? null,
                'is_system_generated' => true,
                'created_by'       => auth()->id(),
                'posted_by'        => auth()->id(),
                'posted_at'        => now(),
            ]);

            $journal->lines()->createMany($lines);

            return $journal->refresh();
        });
    }
}
