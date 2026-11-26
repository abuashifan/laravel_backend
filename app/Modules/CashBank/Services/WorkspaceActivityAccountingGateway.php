<?php

namespace App\Modules\CashBank\Services;

use App\Modules\CashBank\Models\WorkspaceDisbursement;
use App\Modules\Journal\Models\JournalEntry;
use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\TenantConnectionManager;
use App\Shared\Tenant\TenantProductService;
use App\Shared\Workspace\ActivityAccountingGateway;
use Closure;

class WorkspaceActivityAccountingGateway implements ActivityAccountingGateway
{
    public function __construct(
        private readonly TenantProductService $products,
        private readonly TenantConnectionManager $connections,
    ) {}

    public function receive(Company $company, array $approvedActivity): bool
    {
        return $this->withAccounting($company, function () use ($approvedActivity): bool {
            $approvedActivity['source_lines'] = json_encode($approvedActivity['source_lines'], JSON_THROW_ON_ERROR);
            WorkspaceDisbursement::query()->insertOrIgnore($approvedActivity + ['created_at' => now(), 'updated_at' => now()]);

            return true;
        }) ?? false;
    }

    public function status(Company $company, int $document): ?array
    {
        return $this->withAccounting($company, function () use ($document) {
            $row = WorkspaceDisbursement::query()->where('workspace_document_id', $document)->with('cashPayment')->first();
            if ($row === null) {
                return null;
            }
            $payment = $row->cashPayment;
            $journal = $payment?->journal_entry_id ? JournalEntry::query()->find($payment->journal_entry_id) : null;

            return [
                'id' => $row->id,
                'qc_status' => $row->qc_status,
                'qc_note' => $row->qc_note,
                'disbursed_on' => $row->disbursed_on?->toDateString(),
                'payment_reference' => $row->payment_reference,
                'payment_number' => $payment?->payment_number,
                'payment_status' => $payment?->status,
                'journal_entry_id' => $journal?->id,
                'journal_number' => $journal?->journal_number,
                'posted_on' => $payment?->status === 'posted' ? $journal?->journal_date?->toDateString() : null,
                'realized_minor' => $payment?->status === 'posted' && $journal?->status === 'posted' ? $row->amount_minor : 0,
            ];
        });
    }

    private function withAccounting(Company $company, Closure $callback): mixed
    {
        $accounting = $this->products->find($company, TenantDatabase::PRODUCT_ACCOUNTING);
        if ($accounting?->status !== 'active') {
            return null;
        }

        $this->connections->connect($accounting);
        try {
            return $callback();
        } finally {
            $this->connections->disconnect();
        }
    }
}
