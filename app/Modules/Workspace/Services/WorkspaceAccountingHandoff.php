<?php

namespace App\Modules\Workspace\Services;

use App\Shared\Models\Company;
use App\Shared\Workspace\ActivityAccountingGateway;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkspaceAccountingHandoff
{
    public function __construct(private readonly ActivityAccountingGateway $accounting) {}

    public function queue(int $document): void
    {
        $this->records()->insertOrIgnore(['document_id' => $document]);
    }

    public function deliver(Company $company, int $document): bool
    {
        $pending = $this->records()->where('document_id', $document)->whereNull('delivered_at');
        if (! $pending->exists()) {
            return true;
        }

        $error = null;
        try {
            if (! $this->accounting->receive($company, $this->payload($document))) {
                $error = 'Aktifkan Finlite Accounting untuk menerima draf pencairan.';
            }
        } catch (Throwable $exception) {
            report($exception);
            $error = 'Pengiriman ke Accounting tertunda. Coba kirim ulang.';
        }

        $pending->update([
            'attempted_at' => now(),
            'delivered_at' => $error === null ? now() : null,
            'last_error' => $error,
        ]);

        return $error === null;
    }

    public function pending(int $limit): array
    {
        return $this->records()->whereNull('delivered_at')->orderByRaw('CASE WHEN attempted_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('attempted_at')->orderBy('document_id')->limit($limit)->pluck('document_id')->all();
    }

    public function status(Company $company, int $document): array
    {
        $handoff = $this->records()->where('document_id', $document)->first();
        if (! $handoff?->delivered_at) {
            return ['sync_status' => 'pending', 'message' => $handoff?->last_error ?? 'Menunggu pengiriman ke Accounting.'];
        }

        try {
            $status = $this->accounting->status($company, $document);

            return $status === null
                ? ['sync_status' => 'unavailable', 'message' => 'Status Accounting belum dapat diambil.']
                : ['sync_status' => 'delivered'] + $status;
        } catch (Throwable $exception) {
            report($exception);

            return ['sync_status' => 'unavailable', 'message' => 'Status Accounting belum dapat diambil.'];
        }
    }

    private function payload(int $document): array
    {
        $workspace = DB::connection('workspace');
        $activity = $workspace->table('workspace_budget_documents as d')
            ->join('workspace_budget_plans as p', 'p.id', '=', 'd.plan_id')
            ->join('workspace_units as u', 'u.id', '=', 'p.unit_id')
            ->where('d.id', $document)->where('d.kind', 'activity')->where('d.status', 'approved')
            ->first([
                'd.id as workspace_document_id', 'd.annual_version_id as workspace_annual_version_id',
                'p.period_id as workspace_period_id', 'p.unit_id as workspace_unit_id', 'u.name as unit_name',
                'd.version as source_version', 'd.title', 'd.activity_date',
                'd.decided_by as approved_by', 'd.decided_at as approved_at',
            ]);
        if ($activity === null) {
            throw new \LogicException('An accounting handoff requires an approved activity.');
        }

        $lines = $workspace->table('workspace_budget_lines as l')
            ->join('workspace_budget_items as i', 'i.id', '=', 'l.item_id')
            ->where('l.document_id', $document)->orderBy('l.id')
            ->get(['l.id', 'i.code as budget_code', 'l.description', 'l.amount_minor']);

        return (array) $activity + [
            'amount_minor' => $lines->sum('amount_minor'),
            'source_lines' => $lines->map(fn ($line) => (array) $line)->all(),
        ];
    }

    private function records(): Builder
    {
        return DB::connection('workspace')->table('workspace_accounting_handoffs');
    }
}
