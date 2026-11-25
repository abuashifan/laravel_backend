<?php

namespace App\Modules\Workspace\Services;

use App\Modules\Budget\Services\BudgetSourceService;
use App\Modules\Workspace\Models\WorkspaceUnit;
use App\Shared\Exceptions\ApiException;
use App\Shared\Tenant\TenantContext;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkspaceBudgetService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BudgetSourceService $sources,
        private readonly WorkspaceBudgetScope $scope,
    ) {}

    public function units(string $kind): array
    {
        $editable = $this->scope->editableUnits()->pluck('id')->flip();

        return $this->scope->readableUnits($kind)->orderBy('code')->get(['id', 'code', 'name', 'is_active'])
            ->map(fn (WorkspaceUnit $unit) => $unit->toArray() + ['can_submit' => $unit->is_active && $editable->has($unit->id)])
            ->all();
    }

    public function items(string $kind, int $period, int $unit): array
    {
        $this->period($period);
        $this->scope->readableUnits($kind)->findOrFail($unit);

        return $this->table('items as items')
            ->join('workspace_budget_plans as plans', 'plans.id', '=', 'items.plan_id')
            ->where('plans.period_id', $period)->where('plans.unit_id', $unit)->where('items.is_active', true)
            ->orderBy('items.code')
            ->get(['items.id', 'items.code', 'items.name', 'items.limit_minor', 'items.committed_minor'])
            ->all();
    }

    public function summaryByPeriod(): Collection
    {
        return $this->table('items as i')->join('workspace_budget_plans as p', 'p.id', '=', 'i.plan_id')
            ->where('i.is_active', true)->groupBy('p.period_id')
            ->selectRaw('p.period_id, SUM(i.limit_minor) AS limit_minor, SUM(i.committed_minor) AS committed_minor')
            ->get()->keyBy('period_id');
    }

    public function index(array $filters): array
    {
        $this->period($filters['period_id']);
        $kind = $filters['kind'];
        $documents = $this->documents()
            ->where('d.kind', $kind)
            ->where('p.period_id', $filters['period_id'])
            ->whereIn('p.unit_id', $this->scope->readableUnits($kind)->select('id'))
            ->when(isset($filters['unit_id']), fn (Builder $query) => $query->where('p.unit_id', $filters['unit_id']))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('d.status', $filters['status']))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('workspace_budget_documents as next')->whereColumn('next.previous_id', 'd.id'))
            ->orderByDesc('d.id');

        $page = $documents->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);

        return ['data' => $page->items(), 'total' => $page->total()];
    }

    public function show(int $id): object
    {
        $document = $this->document($id);
        $this->scope->readableUnits($document->kind)->findOrFail($document->unit_id);
        $document->lines = $this->table('lines as lines')
            ->leftJoin('workspace_budget_items as items', 'items.id', '=', 'lines.item_id')
            ->where('lines.document_id', $id)->orderBy('lines.id')->get([
                'lines.id', 'lines.item_id', 'lines.code', 'lines.description', 'lines.amount_minor',
                'items.code as item_code', 'items.name as item_name',
            ]);
        $document->next_id = $this->nextVersion($id);

        return $document;
    }

    public function create(array $data, int $actor): object
    {
        $period = $this->period($data['period_id']);
        $id = $this->withUnit($data['unit_id'], function () use ($data, $actor, $period): int {
            $plan = $this->table('plans')->where('period_id', $period->id)->where('unit_id', $data['unit_id'])->first();
            if ($data['kind'] === 'annual') {
                if ($plan) {
                    throw ApiException::make('WORKSPACE_ANNUAL_EXISTS', 'Unit ini sudah memiliki RAB Tahunan. Buka pengajuan yang ada untuk mengubah atau merevisi.', 409);
                }
                $planId = $this->table('plans')->insertGetId(['period_id' => $period->id, 'unit_id' => $data['unit_id']]);
            } else {
                $this->assertAnnualApproved($plan?->approved_annual_id);
                $planId = $plan->id;
            }

            $id = $this->table('documents')->insertGetId([
                'plan_id' => $planId,
                'kind' => $data['kind'],
                'created_by' => $actor,
                'created_at' => now(),
            ] + $this->documentFields($data, $period));
            $this->replaceLines($id, $planId, $data);

            return $id;
        });

        return $this->show($id);
    }

    public function update(int $id, array $data): object
    {
        $document = $this->document($id);
        $period = $this->period($document->period_id);
        $this->withUnit($document->unit_id, function () use ($id, $data, $period): void {
            $document = $this->document($id);
            $this->assertLatestStatus($document, 'draft');
            abort_unless($document->kind === $data['kind'], 422, 'Jenis RAB tidak dapat diubah.');
            $this->table('documents')->where('id', $id)->update($this->documentFields($data, $period));
            $this->replaceLines($id, $document->plan_id, $data);
        });

        return $this->show($id);
    }

    public function submit(int $id, int $actor): object
    {
        $document = $this->document($id);
        $this->withUnit($document->unit_id, function () use ($id, $actor): void {
            $document = $this->document($id);
            $this->assertLatestStatus($document, 'draft');
            if ($document->kind === 'activity') {
                $this->checkCapacity($document);
            }
            $this->table('documents')->where('id', $id)->update([
                'status' => 'submitted', 'submitted_by' => $actor, 'submitted_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $this->show($id);
    }

    public function decide(int $id, string $decision, ?string $note, int $actor): object
    {
        $document = $this->document($id);
        $this->scope->assertCanDecide($document->kind);

        $this->withUnit($document->unit_id, function () use ($id, $decision, $note, $actor): void {
            $document = $this->document($id);
            $this->assertLatestStatus($document, 'submitted');
            $approvedAnnual = null;
            if ($decision === 'approve') {
                if ($document->kind === 'annual') {
                    $this->activateAnnual($document);
                } else {
                    $approvedAnnual = $document->approved_annual_id;
                    foreach ($this->checkCapacity($document) as $item => $amount) {
                        $this->table('items')->where('id', $item)->increment('committed_minor', $amount);
                    }
                }
            }

            $this->table('documents')->where('id', $id)->update([
                'status' => $decision === 'approve' ? 'approved' : 'rejected',
                'annual_version_id' => $approvedAnnual,
                'decided_by' => $actor, 'decided_at' => now(), 'decision_note' => $note, 'updated_at' => now(),
            ]);
        }, editing: false, allowInactive: $decision === 'reject');

        return $this->show($id);
    }

    public function revise(int $id, int $actor): object
    {
        $document = $this->document($id);
        $next = $this->withUnit($document->unit_id, function () use ($id, $actor): int {
            $document = $this->document($id);
            $statuses = $document->kind === 'annual' ? ['approved', 'rejected'] : ['rejected'];
            $this->assertLatestStatus($document, ...$statuses);
            $nextId = $this->table('documents')->insertGetId([
                'plan_id' => $document->plan_id, 'kind' => $document->kind, 'previous_id' => $id,
                'version' => $document->version + 1, 'title' => $document->title, 'activity_date' => $document->activity_date,
                'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $lines = $this->table('lines')->where('document_id', $id)->orderBy('id')
                ->get(['item_id', 'code', 'description', 'amount_minor'])
                ->map(fn ($line) => ['document_id' => $nextId] + (array) $line)->all();
            $this->table('lines')->insert($lines);

            return $nextId;
        });

        return $this->show($next);
    }

    private function activateAnnual(object $document): void
    {
        $new = $this->table('lines')->where('document_id', $document->id)->get()->keyBy('code');
        $current = $this->table('items')->where('plan_id', $document->plan_id)->get()->keyBy('code');
        foreach ($current as $item) {
            if ($item->committed_minor > ($new[$item->code]->amount_minor ?? 0)) {
                throw ApiException::make('WORKSPACE_BUDGET_COMMITMENT_EXISTS', "Pagu {$item->code} tidak boleh kurang dari total komitmen yang disetujui.", 422);
            }
        }

        foreach ($new as $line) {
            $this->table('items')->updateOrInsert(
                ['plan_id' => $document->plan_id, 'code' => $line->code],
                ['name' => $line->description, 'limit_minor' => $line->amount_minor, 'is_active' => true],
            );
        }
        $this->table('items')->where('plan_id', $document->plan_id)->whereNotIn('code', $new->keys())
            ->update(['is_active' => false, 'limit_minor' => 0]);
        $this->table('plans')->where('id', $document->plan_id)->update(['approved_annual_id' => $document->id]);
    }

    private function checkCapacity(object $document): array
    {
        $this->assertAnnualApproved($document->approved_annual_id);
        $requested = $this->table('lines')->where('document_id', $document->id)->groupBy('item_id')
            ->selectRaw('item_id, SUM(amount_minor) AS amount')->pluck('amount', 'item_id')->map(fn ($amount) => (int) $amount)->all();
        $items = $this->table('items')->where('plan_id', $document->plan_id)->whereIn('id', array_keys($requested))->get()->keyBy('id');
        foreach ($requested as $id => $amount) {
            $item = $items->get($id);
            $this->assertActiveItem($item);
            if ($amount > $item->limit_minor - $item->committed_minor) {
                throw ApiException::make('WORKSPACE_BUDGET_LIMIT_EXCEEDED', "Nominal pengajuan melebihi sisa pagu {$item->code}.", 422);
            }
        }

        return $requested;
    }

    private function replaceLines(int $id, int $plan, array $data): void
    {
        $annual = $data['kind'] === 'annual';
        $items = $annual ? collect() : $this->table('items')->where('plan_id', $plan)
            ->whereIn('id', array_column($data['lines'], 'item_id'))->get()->keyBy('id');
        $rows = [];
        foreach ($data['lines'] as $line) {
            if (! $annual) {
                $this->assertActiveItem($items->get($line['item_id']));
            }
            [$whole, $fraction] = array_pad(explode('.', (string) $line['amount'], 2), 2, '');
            $rows[] = [
                'document_id' => $id,
                'item_id' => $annual ? null : $line['item_id'],
                'code' => $annual ? strtoupper($line['code']) : null,
                'description' => $line['description'],
                'amount_minor' => ((int) $whole * 100) + (int) str_pad($fraction, 2, '0'),
            ];
        }
        $this->table('lines')->where('document_id', $id)->delete();
        $this->table('lines')->insert($rows);
    }

    private function documentFields(array $data, object $period): array
    {
        $date = $data['activity_date'] ?? null;
        if ($date !== null && ($date < $period->period_from || $date > $period->period_to)) {
            throw ApiException::make('WORKSPACE_ACTIVITY_DATE_OUTSIDE_PERIOD', 'Tanggal kegiatan harus berada dalam periode pagu.', 422);
        }

        return ['title' => $data['title'], 'activity_date' => $date, 'updated_at' => now()];
    }

    private function assertLatestStatus(object $document, string ...$statuses): void
    {
        if (! in_array($document->status, $statuses, true) || $this->nextVersion($document->id) !== null) {
            throw ApiException::make('WORKSPACE_BUDGET_STATUS_CHANGED', 'Status atau versi RAB sudah berubah. Muat kembali pengajuan.', 409);
        }
    }

    private function assertAnnualApproved(?int $annual): void
    {
        if ($annual === null) {
            throw ApiException::make('WORKSPACE_ANNUAL_REQUIRED', 'RAB Tahunan untuk unit dan periode ini belum disetujui.', 422);
        }
    }

    private function assertActiveItem(?object $item): void
    {
        if (! $item || ! $item->is_active) {
            throw ApiException::make('WORKSPACE_BUDGET_ITEM_INVALID', 'Pos biaya tidak tersedia pada pagu aktif unit dan periode ini.', 422);
        }
    }

    private function nextVersion(int $id): ?int
    {
        return $this->table('documents')->where('previous_id', $id)->value('id');
    }

    private function documents(): Builder
    {
        return $this->table('documents as d')
            ->join('workspace_budget_plans as p', 'p.id', '=', 'd.plan_id')
            ->join('workspace_units as u', 'u.id', '=', 'p.unit_id')
            ->select('d.*', 'p.period_id', 'p.unit_id', 'p.approved_annual_id', 'u.name as unit_name', 'u.is_active as unit_active')
            ->selectSub($this->table('lines')->selectRaw('COALESCE(SUM(amount_minor), 0)')->whereColumn('document_id', 'd.id'), 'total_minor');
    }

    private function document(int $id): object
    {
        $record = $this->documents()->where('d.id', $id)->first();
        abort_if($record === null, 404);

        return $record;
    }

    private function period(int $id): object
    {
        $record = $this->sources->periods($this->context->companyId())->find($id);
        abort_if($record === null, 404);

        return $record;
    }

    private function withUnit(int $id, Closure $work, bool $editing = true, bool $allowInactive = false): mixed
    {
        if ($editing) {
            $this->scope->editableUnits()->findOrFail($id);
        }

        // Approval, revisi pagu, dan pembuatan dokumen mengambil lock yang sama.
        return $this->connection()->transaction(function () use ($id, $work, $editing, $allowInactive) {
            if ($this->connection()->getDriverName() === 'sqlite') {
                $this->connection()->table('workspace_units')->where('id', $id)->update(['id' => DB::raw('id')]);
            }
            $unit = WorkspaceUnit::query()->lockForUpdate()->findOrFail($id);
            if ($editing) {
                $this->scope->editableUnits()->findOrFail($id);
            }
            if (! $allowInactive) {
                $this->scope->assertActive($unit);
            }

            return $work();
        }, 3);
    }

    private function table(string $table): Builder
    {
        return $this->connection()->table('workspace_budget_'.$table);
    }

    private function connection(): Connection
    {
        return DB::connection('workspace');
    }
}
