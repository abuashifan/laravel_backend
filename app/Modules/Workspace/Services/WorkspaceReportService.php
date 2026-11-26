<?php

namespace App\Modules\Workspace\Services;

use App\Shared\Budget\BudgetSourceService;
use App\Shared\Tenant\TenantContext;
use App\Shared\Workspace\ActivityAccountingGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Laporan manajemen anggaran Fase 4: seluruh unit, kegiatan per unit, dan rincian
 * satu kegiatan. Ketiganya memakai sumber yang sama (RAB disetujui di Workspace dan
 * kas keluar posted di Accounting) sehingga total tiap tingkat saling cocok.
 */
class WorkspaceReportService
{
    private const MEASURES = ['limit_minor', 'committed_minor', 'approved_minor', 'disbursed_minor', 'awaiting_minor', 'realized_minor'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly BudgetSourceService $sources,
        private readonly WorkspaceBudgetScope $scope,
        private readonly ActivityAccountingGateway $accounting,
    ) {}

    public function units(int $periodId): array
    {
        $period = $this->period($periodId);
        $units = $this->readableUnits();
        $plans = $this->plans($periodId, $units->keys()->all());
        $items = DB::connection('workspace')->table('workspace_budget_items')->whereIn('plan_id', $plans->keys())->where('is_active', true)
            ->groupBy('plan_id')->get(['plan_id', DB::raw('SUM(limit_minor) AS limit_minor'), DB::raw('SUM(committed_minor) AS committed_minor')])
            ->keyBy('plan_id');
        $activities = $this->activities($plans->keys()->all());
        [$accounting, $available] = $this->realization($activities->keys()->all());

        $rows = $units->map(function (object $unit) use ($plans, $items, $activities, $accounting): array {
            $plan = $plans->firstWhere('unit_id', $unit->id);
            $budget = $plan ? $items->get($plan->id) : null;
            $own = $plan ? $activities->where('plan_id', $plan->id) : collect();

            return [
                'unit_id' => $unit->id, 'unit_code' => $unit->code, 'unit_name' => $unit->name,
                'limit_minor' => (int) ($budget->limit_minor ?? 0),
                'committed_minor' => (int) ($budget->committed_minor ?? 0),
                'activity_count' => $own->count(),
            ] + $this->activityTotals($own, $accounting);
        })->filter(fn (array $row) => $row['limit_minor'] > 0 || $row['activity_count'] > 0)->values()
            ->map(fn (array $row) => $row + ['remaining_minor' => $row['limit_minor'] - $row['committed_minor']]);

        return [
            'period' => $period,
            'accounting_available' => $available,
            'scope' => $this->hasAllUnits() ? 'organization' : 'assigned_units',
            'rows' => $rows->all(),
            'total' => $this->sum($rows) + ['remaining_minor' => $rows->sum('remaining_minor')],
        ];
    }

    public function unit(int $periodId, int $unitId): array
    {
        $period = $this->period($periodId);
        $unit = $this->readableUnits()->get($unitId);
        abort_if($unit === null, 404);
        $plans = $this->plans($periodId, [$unitId]);
        $activities = $this->activities($plans->keys()->all());
        [$accounting, $available] = $this->realization($activities->keys()->all());

        $rows = $activities->map(fn (object $activity) => [
            'document_id' => $activity->id, 'title' => $activity->title, 'activity_date' => $activity->activity_date,
            'version' => $activity->version, 'approved_at' => $activity->decided_at,
            'qc_status' => $accounting[$activity->id]['qc_status'] ?? null,
            'payment_status' => $accounting[$activity->id]['payment_status'] ?? null,
        ] + $this->activityTotals(collect([$activity]), $accounting))->values()
            ->map(fn (array $row) => $row + ['variance_minor' => $row['approved_minor'] - $row['realized_minor']]);

        return [
            'period' => $period, 'accounting_available' => $available,
            'unit' => ['id' => $unit->id, 'code' => $unit->code, 'name' => $unit->name],
            'rows' => $rows->all(),
            'total' => $this->sum($rows) + ['variance_minor' => $rows->sum('variance_minor')],
        ];
    }

    public function activity(int $documentId): array
    {
        $activity = DB::connection('workspace')->table('workspace_budget_documents as d')
            ->join('workspace_budget_plans as p', 'p.id', '=', 'd.plan_id')
            ->join('workspace_units as u', 'u.id', '=', 'p.unit_id')
            ->where('d.id', $documentId)->where('d.kind', 'activity')->where('d.status', 'approved')
            ->first(['d.id', 'd.title', 'd.activity_date', 'd.version', 'd.decided_at', 'p.period_id', 'u.id as unit_id', 'u.code as unit_code', 'u.name as unit_name']);
        abort_if($activity === null || ! $this->readableUnits()->has($activity->unit_id), 404);
        [$accounting, $available] = $this->realization([$documentId]);
        $source = $accounting[$documentId] ?? null;

        $rows = DB::connection('workspace')->table('workspace_budget_lines as l')
            ->leftJoin('workspace_budget_items as i', 'i.id', '=', 'l.item_id')
            ->where('l.document_id', $documentId)->orderBy('l.id')
            ->get(['l.id', 'i.code as item_code', 'i.name as item_name', 'l.description', 'l.amount_minor'])
            ->map(function (object $line) use ($source): array {
                $realized = (int) ($source['lines'][$line->id] ?? 0);

                return [
                    'line_id' => $line->id, 'item_code' => $line->item_code, 'item_name' => $line->item_name,
                    'description' => $line->description, 'approved_minor' => (int) $line->amount_minor,
                    'realized_minor' => $realized, 'variance_minor' => (int) $line->amount_minor - $realized,
                ];
            });

        return [
            'period' => $this->period((int) $activity->period_id), 'accounting_available' => $available,
            'activity' => [
                'id' => $activity->id, 'title' => $activity->title, 'activity_date' => $activity->activity_date,
                'version' => $activity->version, 'approved_at' => $activity->decided_at,
                'unit_id' => $activity->unit_id, 'unit_code' => $activity->unit_code, 'unit_name' => $activity->unit_name,
            ],
            'accounting' => $source === null ? null : collect($source)->except('lines')->all(),
            'rows' => $rows->all(),
            'total' => [
                'approved_minor' => $rows->sum('approved_minor'), 'realized_minor' => $rows->sum('realized_minor'),
                'variance_minor' => $rows->sum('variance_minor'),
                'disbursed_minor' => (int) ($source['disbursed_minor'] ?? 0), 'awaiting_minor' => (int) ($source['awaiting_minor'] ?? 0),
            ],
        ];
    }

    public function reconciliation(int $periodId): array
    {
        $period = $this->period($periodId);
        // Rekonsiliasi membaca seluruh jurnal biaya perusahaan, jadi hanya untuk akses lintas unit.
        abort_unless($this->hasAllUnits(), 403);
        $plans = $this->plans($periodId, $this->readableUnits()->keys()->all());
        $activities = $this->activities($plans->keys()->all());
        [$accounting, $available] = $this->realization($activities->keys()->all());
        try {
            $journals = $this->accounting->reconciliation($this->context->company(), $period->period_from, $period->period_to, $activities->keys()->all());
        } catch (Throwable $exception) {
            report($exception);
            $journals = null;
        }

        return [
            'period' => $period,
            'accounting_available' => $available && $journals !== null,
            'workspace_realized_minor' => array_sum(array_column($accounting, 'realized_minor')),
            'workspace_awaiting_minor' => array_sum(array_column($accounting, 'awaiting_minor')),
            'undelivered_count' => $activities->keys()->diff(array_keys($accounting))->count(),
        ] + ($journals ?? []);
    }

    private function period(int $id): object
    {
        $period = $this->sources->periods($this->context->companyId())->find($id, ['id', 'name', 'period_from', 'period_to']);
        abort_if($period === null, 404);
        $period->period_from = substr((string) $period->period_from, 0, 10);
        $period->period_to = substr((string) $period->period_to, 0, 10);

        return $period;
    }

    /** @return Collection<int, object> unit yang boleh dibaca, dikunci ID. */
    private function readableUnits(): Collection
    {
        return $this->scope->readableUnits('activity')->orderBy('code')->get(['id', 'code', 'name'])
            ->mapWithKeys(fn ($unit) => [$unit->id => (object) $unit->only(['id', 'code', 'name'])]);
    }

    private function hasAllUnits(): bool
    {
        return $this->readableUnits()->count() === DB::connection('workspace')->table('workspace_units')->count();
    }

    private function plans(int $periodId, array $units): Collection
    {
        return DB::connection('workspace')->table('workspace_budget_plans')->where('period_id', $periodId)
            ->whereIn('unit_id', $units)->get(['id', 'unit_id'])->keyBy('id');
    }

    /** RAB Kegiatan disetujui beserta total barisnya, dikunci ID dokumen. */
    private function activities(array $plans): Collection
    {
        return DB::connection('workspace')->table('workspace_budget_documents as d')
            ->join('workspace_budget_lines as l', 'l.document_id', '=', 'd.id')
            ->whereIn('d.plan_id', $plans)->where('d.kind', 'activity')->where('d.status', 'approved')
            ->groupBy('d.id', 'd.plan_id', 'd.title', 'd.activity_date', 'd.version', 'd.decided_at')
            ->orderBy('d.activity_date')->orderBy('d.id')
            ->get(['d.id', 'd.plan_id', 'd.title', 'd.activity_date', 'd.version', 'd.decided_at', DB::raw('SUM(l.amount_minor) AS approved_minor')])
            ->keyBy('id');
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: bool} */
    private function realization(array $documents): array
    {
        try {
            $rows = $this->accounting->realization($this->context->company(), $documents);
        } catch (Throwable $exception) {
            report($exception);
            $rows = null;
        }

        return [$rows ?? [], $rows !== null];
    }

    private function activityTotals(Collection $activities, array $accounting): array
    {
        $realization = fn (string $key) => $activities->sum(fn ($activity) => (int) ($accounting[$activity->id][$key] ?? 0));

        return [
            'approved_minor' => $activities->sum(fn ($activity) => (int) $activity->approved_minor),
            'disbursed_minor' => $realization('disbursed_minor'),
            'awaiting_minor' => $realization('awaiting_minor'),
            'realized_minor' => $realization('realized_minor'),
        ];
    }

    private function sum(Collection $rows): array
    {
        return collect(self::MEASURES)->filter(fn (string $key) => $rows->every(fn (array $row) => array_key_exists($key, $row)))
            ->mapWithKeys(fn (string $key) => [$key => $rows->sum($key)])->all();
    }
}
