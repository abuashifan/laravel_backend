<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\BudgetLine;
use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Models\BudgetSubmission;
use App\Modules\Budget\Support\BudgetDirection;
use App\Modules\Budget\Support\BudgetState;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Project;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Exceptions\ApiException;
use App\Shared\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ONE BUDGET ENGINE → MULTIPLE DIMENSIONS → MULTIPLE VIEWS.
 *
 * Semua view anggaran (per akun, per cost center, per proyek, per bulan, cash
 * budget, project profitability) lahir dari agregasi yang sama di sini. Drill-down
 * bukan query terpisah: ia panggilan ulang dengan `group_by` lebih panjang dan
 * nilai baris induk sebagai filter, sehingga angkanya konsisten di tiap level
 * secara konstruksi — bukan karena empat query kebetulan mirip.
 */
class BudgetAnalysisService
{
    /** Dimensi yang boleh dipakai `group_by`. Nilai di luar ini ditolak 422, tidak pernah menyentuh SQL. */
    public const GROUPABLE = ['department', 'project', 'account', 'period', 'direction'];

    public const MODES = ['summary', 'detail', 'variance'];

    /**
     * Satu baris agregasi bisa memuat akun pendapatan DAN beban sekaligus
     * (mis. `group_by=[department]` tanpa filter arah). "Favorable" tidak punya
     * arti tunggal di baris seperti itu, jadi arahnya ditandai `mixed` dan
     * variance-nya memakai konvensi beban. Nilai ini hanya muncul di keluaran
     * analisis — `budget_lines.direction` tidak pernah berisi ini.
     */
    public const DIRECTION_MIXED = 'mixed';

    /** Kolom sumber tiap dimensi di sisi anggaran. */
    private const BUDGET_DIMENSION_COLUMNS = [
        'account' => 'budget_lines.account_id',
        'department' => 'budget_lines.department_id',
        'project' => 'budget_lines.project_id',
        'direction' => 'budget_lines.direction',
        'period' => 'budget_lines.period_month',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BudgetActualService $actualService,
        private readonly BudgetAllocationResolver $allocationResolver,
    ) {}

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function analyze(array $params): array
    {
        $period = $this->resolvePeriod($params);
        $groupBy = $this->validateGroupBy($params['group_by'] ?? []);
        $mode = $this->validateMode($params['mode'] ?? 'summary');
        $allocation = $this->validateAllocation($params['allocation'] ?? BudgetAllocationResolver::ANNUAL_ROW);

        [$dateFrom, $dateTo] = $this->resolveDateRange($period, $params);
        $filters = $this->extractFilters($params);
        $submissionIds = $this->resolveSubmissionIds($period, $params['version'] ?? 'active');

        $budgetRows = $this->aggregateBudget($period, $submissionIds, $groupBy, $filters, $allocation, $mode);
        $actualRows = $this->aggregateActual($period, $groupBy, $filters, $dateFrom, $dateTo);

        $rows = $this->mergeRows($budgetRows, $actualRows, $groupBy, $mode);
        $rows = $this->attachLabels($rows, $groupBy);

        return [
            'period' => [
                'budget_period_id' => $period->id,
                'name' => $period->name,
                'fiscal_year' => $period->fiscal_year,
                'period_from' => CarbonImmutable::parse($period->period_from)->toDateString(),
                'period_to' => CarbonImmutable::parse($period->period_to)->toDateString(),
            ],
            'rows' => $rows,
            'totals' => $this->totals($rows),
            'meta' => [
                'group_by' => $groupBy,
                'mode' => $mode,
                'allocation' => $allocation,
                'version' => (string) ($params['version'] ?? 'active'),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                // Actual dipotong rentang, anggaran tetap penuh. Tanpa penanda
                // ini, membandingkan realisasi separuh periode dengan anggaran
                // sepenuh periode terbaca seolah semuanya hemat.
                'is_partial_period' => $this->isPartialPeriod($period, $dateFrom, $dateTo),
                'submission_ids' => $submissionIds,
            ],
        ];
    }

    // ---------------------------------------------------------------- validasi

    private function resolvePeriod(array $params): BudgetPeriod
    {
        return BudgetPeriod::query()
            ->forCompany($this->tenantContext->companyId())
            ->findOrFail((int) ($params['budget_period_id'] ?? 0));
    }

    /**
     * @return array<int,string>
     */
    private function validateGroupBy(mixed $groupBy): array
    {
        $groupBy = array_values(array_unique(array_map('strval', (array) $groupBy)));

        foreach ($groupBy as $dimension) {
            if (! in_array($dimension, self::GROUPABLE, true)) {
                throw ApiException::make(
                    ApiErrorCode::BUDGET_INVALID_GROUP_BY,
                    "Dimensi pengelompokan tidak dikenal: {$dimension}.",
                    422,
                    ['group_by' => ['Pilih dari: '.implode(', ', self::GROUPABLE).'.']],
                );
            }
        }

        return $groupBy;
    }

    private function validateMode(mixed $mode): string
    {
        $mode = (string) $mode;

        if (! in_array($mode, self::MODES, true)) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                "Mode analisis tidak dikenal: {$mode}.",
                422,
                ['mode' => ['Pilih dari: '.implode(', ', self::MODES).'.']],
            );
        }

        return $mode;
    }

    private function validateAllocation(mixed $allocation): string
    {
        $allocation = (string) $allocation;

        if (! in_array($allocation, BudgetAllocationResolver::modes(), true)) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                "Mode alokasi tidak dikenal: {$allocation}.",
                422,
                ['allocation' => ['Pilih dari: '.implode(', ', BudgetAllocationResolver::modes()).'.']],
            );
        }

        return $allocation;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveDateRange(BudgetPeriod $period, array $params): array
    {
        $periodFrom = CarbonImmutable::parse($period->period_from);
        $periodTo = CarbonImmutable::parse($period->period_to);

        $from = ! empty($params['date_from']) ? CarbonImmutable::parse($params['date_from']) : $periodFrom;
        $to = ! empty($params['date_to']) ? CarbonImmutable::parse($params['date_to']) : $periodTo;

        // Rentang di luar periode anggaran tidak punya pembanding anggaran, jadi
        // selalu dipotong ke dalam periode.
        $from = $from->lessThan($periodFrom) ? $periodFrom : $from;
        $to = $to->greaterThan($periodTo) ? $periodTo : $to;

        return [$from->toDateString(), $to->toDateString()];
    }

    private function isPartialPeriod(BudgetPeriod $period, string $dateFrom, string $dateTo): bool
    {
        return $dateFrom !== CarbonImmutable::parse($period->period_from)->toDateString()
            || $dateTo !== CarbonImmutable::parse($period->period_to)->toDateString();
    }

    /**
     * @return array<string,mixed>
     */
    private function extractFilters(array $params): array
    {
        return array_filter([
            'department_id' => isset($params['department_id']) ? (int) $params['department_id'] : null,
            'project_id' => isset($params['project_id']) ? (int) $params['project_id'] : null,
            'account_id' => isset($params['account_id']) ? (int) $params['account_id'] : null,
            'account_type' => $params['account_type'] ?? null,
            'direction' => $params['direction'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array<int,int>
     */
    private function resolveSubmissionIds(BudgetPeriod $period, mixed $version): array
    {
        $query = BudgetSubmission::query()
            ->forCompany($this->tenantContext->companyId())
            ->where('budget_period_id', $period->id);

        if (is_numeric($version)) {
            return $query->whereKey((int) $version)->pluck('id')->all();
        }

        if ($version === 'all') {
            // Semua versi yang pernah disetujui, termasuk yang sudah digantikan.
            return $query->whereIn('status', ['approved', 'superseded'])->pluck('id')->all();
        }

        return $query->where('status', 'approved')->active()->pluck('id')->all();
    }

    // ------------------------------------------------------------ sisi anggaran

    /**
     * @param  array<int,int>  $submissionIds
     * @param  array<int,string>  $groupBy
     * @return array<string,array<string,mixed>>
     */
    private function aggregateBudget(
        BudgetPeriod $period,
        array $submissionIds,
        array $groupBy,
        array $filters,
        string $allocation,
        string $mode,
    ): array {
        if ($submissionIds === []) {
            return [];
        }

        $query = BudgetLine::query()
            ->whereIn('budget_lines.budget_submission_id', $submissionIds);

        if (! empty($filters['department_id'])) {
            $query->where('budget_lines.department_id', $filters['department_id']);
        }
        if (! empty($filters['project_id'])) {
            $query->where('budget_lines.project_id', $filters['project_id']);
        }
        if (! empty($filters['account_id'])) {
            $query->where('budget_lines.account_id', $filters['account_id']);
        }
        if (! empty($filters['direction'])) {
            $query->where('budget_lines.direction', $filters['direction']);
        }
        if (! empty($filters['account_type'])) {
            $query->whereIn(
                'budget_lines.account_id',
                ChartOfAccount::query()->where('account_type', $filters['account_type'])->select('id'),
            );
        }

        $groupByPeriod = in_array('period', $groupBy, true);
        $lines = $query->get();
        $rows = [];

        foreach ($lines as $line) {
            foreach ($this->budgetBuckets($line, $period, $groupBy, $groupByPeriod, $allocation) as [$dimensions, $amount]) {
                $key = $this->dimensionKey($dimensions, $groupBy);
                $rows[$key] ??= [
                    'dimensions' => $dimensions,
                    'budget_amount' => 0.0,
                    'actual_amount' => 0.0,
                    'directions' => [],
                    'lines' => [],
                ];
                $rows[$key]['budget_amount'] += $amount;
                $rows[$key]['directions'][$line->direction] = true;

                if ($mode === 'detail') {
                    $rows[$key]['lines'][] = [
                        'id' => $line->id,
                        'account_id' => $line->account_id,
                        'department_id' => $line->department_id,
                        'project_id' => $line->project_id,
                        'period_month' => $line->period_month,
                        'direction' => $line->direction,
                        'amount' => number_format((float) $line->amount, 2, '.', ''),
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Satu baris anggaran bisa jatuh ke lebih dari satu bucket saat
     * `allocation=even` memecah baris tahunan ke tiap bulan.
     *
     * @return array<int,array{0:array<string,mixed>,1:float}>
     */
    private function budgetBuckets(
        BudgetLine $line,
        BudgetPeriod $period,
        array $groupBy,
        bool $groupByPeriod,
        string $allocation,
    ): array {
        $base = [];
        foreach ($groupBy as $dimension) {
            $base[$dimension] = match ($dimension) {
                'account' => $line->account_id,
                'department' => $line->department_id,
                'project' => $line->project_id,
                'direction' => $line->direction,
                'period' => $line->period_month,
                default => null,
            };
        }

        if (! $groupByPeriod) {
            return [[$base, (float) $line->amount]];
        }

        // Baris bulanan sudah punya bucket-nya sendiri lewat `period_month`.
        if ($line->period_month !== null || $allocation !== BudgetAllocationResolver::EVEN) {
            // `annual_row`: baris tahunan tetap di bucket `period = null`, muncul
            // sebagai "Tahunan (belum dialokasikan)" — bukan dipecah jadi angka
            // bulanan palsu.
            return [[$base, (float) $line->amount]];
        }

        $buckets = [];
        foreach ($this->allocationResolver->monthsIn($period) as $month) {
            $buckets[] = [
                ['period' => $month] + $base,
                $this->allocationResolver->amountForMonth($line, $period, $month, $allocation),
            ];
        }

        return $buckets;
    }

    // -------------------------------------------------------------- sisi actual

    /**
     * @param  array<int,string>  $groupBy
     * @return array<string,array<string,mixed>>
     */
    private function aggregateActual(BudgetPeriod $period, array $groupBy, array $filters, string $dateFrom, string $dateTo): array
    {
        $actualDimensions = array_values(array_filter($groupBy, fn ($d) => $d !== 'period'));
        $rows = [];

        if (! in_array('period', $groupBy, true)) {
            foreach ($this->actualService->aggregate($dateFrom, $dateTo, $actualDimensions, $filters) as $row) {
                $this->mergeActualRow($rows, $row['dimensions'], $row['actual_amount'], $groupBy);
            }

            return $rows;
        }

        // Pengelompokan bulan dilakukan dengan menjalankan agregasi per rentang
        // tanggal bulan — bukan `strftime()`, yang hanya ada di SQLite (G9).
        foreach ($this->allocationResolver->monthsIn($period, $dateFrom, $dateTo) as $month) {
            [$monthFrom, $monthTo] = $this->allocationResolver->monthBounds($month, $dateFrom, $dateTo);

            foreach ($this->actualService->aggregate($monthFrom, $monthTo, $actualDimensions, $filters) as $row) {
                $this->mergeActualRow($rows, ['period' => $month] + $row['dimensions'], $row['actual_amount'], $groupBy);
            }
        }

        return $rows;
    }

    /**
     * @param  array<string,array<string,mixed>>  $rows
     */
    private function mergeActualRow(array &$rows, array $dimensions, float $amount, array $groupBy): void
    {
        $ordered = [];
        foreach ($groupBy as $dimension) {
            $ordered[$dimension] = $dimensions[$dimension] ?? null;
        }

        $key = $this->dimensionKey($ordered, $groupBy);
        $rows[$key] ??= ['dimensions' => $ordered, 'actual_amount' => 0.0];
        $rows[$key]['actual_amount'] += $amount;
    }

    // ------------------------------------------------------------------ merging

    /**
     * @return array<int,array<string,mixed>>
     */
    private function mergeRows(array $budgetRows, array $actualRows, array $groupBy, string $mode): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($budgetRows), array_keys($actualRows))));
        $rows = [];

        foreach ($keys as $key) {
            $budget = $budgetRows[$key] ?? null;
            $actual = $actualRows[$key] ?? null;

            $budgetAmount = (float) ($budget['budget_amount'] ?? 0);
            $actualAmount = (float) ($actual['actual_amount'] ?? 0);
            $dimensions = $budget['dimensions'] ?? $actual['dimensions'] ?? [];
            $direction = $this->resolveDirection($budget, $dimensions, $groupBy);

            $variance = BudgetState::variance($budgetAmount, $actualAmount, $direction);

            $row = [];
            foreach ($groupBy as $dimension) {
                $row[$dimension.'_id'] = $dimensions[$dimension] ?? null;
            }
            // `period` dan `direction` bukan foreign key — namanya jangan berakhiran _id.
            if (array_key_exists('period_id', $row)) {
                $row['period_month'] = $row['period_id'];
                unset($row['period_id']);
            }
            if (array_key_exists('direction_id', $row)) {
                unset($row['direction_id']);
            }

            $row += [
                'direction' => $direction,
                'budget_amount' => number_format($budgetAmount, 2, '.', ''),
                'actual_amount' => number_format($actualAmount, 2, '.', ''),
                'variance' => number_format($variance, 2, '.', ''),
                'variance_pct' => BudgetState::variancePct($budgetAmount, $variance),
                'utilization_pct' => BudgetState::utilizationPct($budgetAmount, $actualAmount),
                'state' => BudgetState::resolve($budgetAmount, $actualAmount, $direction),
            ];

            if ($mode === 'detail') {
                $row['lines'] = $budget['lines'] ?? [];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Arah baris: dari kunci `direction` kalau dikelompokkan, dari baris anggaran
     * kalau seragam, `mixed` kalau bercampur.
     */
    private function resolveDirection(?array $budget, array $dimensions, array $groupBy): string
    {
        if (in_array('direction', $groupBy, true) && ! empty($dimensions['direction'])) {
            return (string) $dimensions['direction'];
        }

        $directions = array_keys(array_filter($budget['directions'] ?? []));

        if (count($directions) === 1) {
            return (string) $directions[0];
        }

        if ($directions === []) {
            // Hanya ada actual tanpa anggaran. Konvensi beban dipakai supaya
            // "actual tanpa anggaran" terbaca unfavorable, bukan sebaliknya.
            return BudgetDirection::EXPENSE;
        }

        return self::DIRECTION_MIXED;
    }

    private function dimensionKey(array $dimensions, array $groupBy): string
    {
        $parts = [];
        foreach ($groupBy as $dimension) {
            $value = $dimensions[$dimension] ?? null;
            $parts[] = $value === null ? '~' : (string) $value;
        }

        return implode('|', $parts);
    }

    // ------------------------------------------------------------------- label

    /**
     * @return array<int,array<string,mixed>>
     */
    private function attachLabels(array $rows, array $groupBy): array
    {
        $accountIds = $this->collectIds($rows, 'account_id');
        $departmentIds = $this->collectIds($rows, 'department_id');
        $projectIds = $this->collectIds($rows, 'project_id');

        $accounts = $accountIds === [] ? collect() : ChartOfAccount::query()->whereIn('id', $accountIds)->get()->keyBy('id');
        $departments = $departmentIds === [] ? collect() : Department::query()->whereIn('id', $departmentIds)->get()->keyBy('id');
        $projects = $projectIds === [] ? collect() : Project::query()->whereIn('id', $projectIds)->get()->keyBy('id');

        foreach ($rows as &$row) {
            if (in_array('account', $groupBy, true)) {
                $account = $row['account_id'] ? $accounts->get($row['account_id']) : null;
                $row['account_code'] = $account?->account_code;
                $row['account_name'] = $account?->account_name;
            }
            if (in_array('department', $groupBy, true)) {
                $row['department_name'] = $row['department_id']
                    ? $departments->get($row['department_id'])?->name
                    : null;
            }
            if (in_array('project', $groupBy, true)) {
                $row['project_name'] = $row['project_id']
                    ? $projects->get($row['project_id'])?->name
                    : null;
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array<int,int>
     */
    private function collectIds(array $rows, string $key): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($row) => isset($row[$key]) ? (int) $row[$key] : null,
            $rows,
        ))));
    }

    /**
     * @return array<string,string|null>
     */
    private function totals(array $rows): array
    {
        $budget = array_sum(array_map(fn ($row) => (float) $row['budget_amount'], $rows));
        $actual = array_sum(array_map(fn ($row) => (float) $row['actual_amount'], $rows));

        return [
            'budget_amount' => number_format($budget, 2, '.', ''),
            'actual_amount' => number_format($actual, 2, '.', ''),
            // Total memakai konvensi beban: positif = belum terpakai. Baris
            // pendapatan punya konvensi sendiri, jadi angka ini hanya bermakna
            // pada tampilan yang sudah difilter satu arah.
            'variance' => number_format($budget - $actual, 2, '.', ''),
            'utilization_pct' => BudgetState::utilizationPct($budget, $actual),
        ];
    }

    /**
     * Anggaran per akun untuk satu himpunan submission — dipakai pembungkus
     * konsolidasi yang butuh bentuk lama (akun bersarang di dalam dimensi).
     *
     * @param  array<int,int>  $submissionIds
     */
    public function budgetTotalsByAccount(array $submissionIds): array
    {
        if ($submissionIds === []) {
            return [];
        }

        return BudgetLine::query()
            ->whereIn('budget_submission_id', $submissionIds)
            ->select('account_id', DB::raw('SUM(amount) as total_amount'))
            ->groupBy('account_id')
            ->pluck('total_amount', 'account_id')
            ->all();
    }
}
