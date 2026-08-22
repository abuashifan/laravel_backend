<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Support\BudgetDirection;
use App\Modules\Reports\Services\ReportQueryService;
use App\Shared\Reports\Data\ReportDateRange;
use App\Shared\Reports\Data\ReportDimensionFilter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Actual anggaran, selalu dari ledger yang sudah ada — tidak ada tabel actual
 * dan tidak ada input manual.
 *
 * Dua cacat lama tertutup di sini:
 *
 * - **G4** — `BudgetComparisonService` lama membangun querynya sendiri dan lupa
 *   `is_obsolete`, sehingga jurnal yang sudah digantikan tetap terhitung sebagai
 *   realisasi. Sekarang basisnya WAJIB `reportableJournalLinesQuery()`, satu
 *   sumber yang sama dengan seluruh laporan lain.
 * - **G5** — `SUM(debit − credit)` untuk semua akun mengasumsikan akun bersaldo
 *   debit. Akibatnya actual akun pendapatan selalu negatif dan `over_budget`
 *   tidak pernah menyala. Sekarang tandanya dibalik per jenis akun.
 *
 * **G9** juga tertutup: pengelompokan bulan memakai rentang tanggal, bukan
 * `strftime()` yang hanya ada di SQLite.
 */
class BudgetActualService
{
    /** Dimensi actual → kolom sumbernya. */
    private const DIMENSION_COLUMNS = [
        'account' => 'jel.account_id',
        'department' => 'jel.department_id',
        'project' => 'jel.project_id',
        'direction' => 'coa.account_type',
    ];

    public function __construct(private readonly ReportQueryService $reportQueryService) {}

    /**
     * Jumlah actual per kombinasi dimensi untuk satu rentang tanggal.
     *
     * @param  array<int,string>  $groupBy  subset dari account|department|project|direction
     * @param  array<string,mixed>  $filters
     * @return array<string,array<string,mixed>> dikunci oleh tuple dimensi
     */
    public function aggregate(string $dateFrom, string $dateTo, array $groupBy, array $filters = []): array
    {
        $query = $this->baseQuery($dateFrom, $dateTo, $filters);

        $selects = [DB::raw($this->signedSumExpression().' as actual_amount')];
        $groups = [];

        foreach ($groupBy as $dimension) {
            if (! isset(self::DIMENSION_COLUMNS[$dimension])) {
                continue;
            }
            $column = self::DIMENSION_COLUMNS[$dimension];
            $selects[] = DB::raw($column.' as '.$dimension.'_key');
            $groups[] = $column;
        }

        $query->select($selects);
        if ($groups !== []) {
            $query->groupBy($groups);
        }

        $rows = [];
        foreach ($query->get() as $row) {
            $keyParts = [];
            foreach ($groupBy as $dimension) {
                if (! isset(self::DIMENSION_COLUMNS[$dimension])) {
                    continue;
                }
                $value = $row->{$dimension.'_key'} ?? null;
                if ($dimension === 'direction') {
                    $value = BudgetDirection::fromAccountType($value === null ? null : (string) $value);
                }
                $keyParts[$dimension] = $value;
            }

            $rows[] = ['dimensions' => $keyParts, 'actual_amount' => (float) $row->actual_amount];
        }

        return $rows;
    }

    /**
     * Actual untuk satu kombinasi dimensi persis — dipakai peringatan
     * over-budget, yang menanyakan satu baris jurnal pada satu waktu.
     *
     * `null` pada departemen/proyek berarti "baris tanpa dimensi itu", bukan
     * "abaikan filternya" — perbedaan yang dulu menjadi G8.
     */
    public function sumFor(
        int $accountId,
        ?int $departmentId,
        ?int $projectId,
        string $dateFrom,
        string $dateTo,
        bool $matchNullDimensionsExactly = true,
    ): float {
        $query = $this->baseQuery($dateFrom, $dateTo, ['account_id' => $accountId]);

        if ($departmentId !== null) {
            $query->where('jel.department_id', $departmentId);
        } elseif ($matchNullDimensionsExactly) {
            $query->whereNull('jel.department_id');
        }

        if ($projectId !== null) {
            $query->where('jel.project_id', $projectId);
        } elseif ($matchNullDimensionsExactly) {
            $query->whereNull('jel.project_id');
        }

        return (float) $query->value(DB::raw('COALESCE('.$this->signedSumExpression().', 0)'));
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function baseQuery(string $dateFrom, string $dateTo, array $filters): Builder
    {
        $query = $this->reportQueryService->reportableJournalLinesQuery();

        $this->reportQueryService->applyDateRange(
            $query,
            new ReportDateRange(startDate: $dateFrom, endDate: $dateTo),
        );

        $this->reportQueryService->applyDimensionFilter(
            $query,
            ReportDimensionFilter::fromArray(array_filter([
                'department_id' => $filters['department_id'] ?? null,
                'project_id' => $filters['project_id'] ?? null,
            ], fn ($value) => $value !== null)),
        );

        // Join CoA dipasang sendiri, bukan lewat `applyAccountTypeFilter()`:
        // jenis akun selalu dibutuhkan untuk membalik tanda, dan method itu akan
        // menambah join kedua ke alias `coa` yang sama begitu filternya dipakai.
        $query->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id');

        if (! empty($filters['account_id'])) {
            $query->where('jel.account_id', (int) $filters['account_id']);
        }
        if (! empty($filters['account_type'])) {
            $query->where('coa.account_type', (string) $filters['account_type']);
        }
        if (! empty($filters['direction'])) {
            $query->where('coa.account_type', (string) $filters['direction']);
        }
        if (! empty($filters['account_ids'])) {
            $query->whereIn('jel.account_id', (array) $filters['account_ids']);
        }

        return $query;
    }

    /**
     * Akun pendapatan bersaldo kredit, akun beban bersaldo debit. Tanpa dibalik,
     * realisasi pendapatan selalu keluar negatif.
     */
    private function signedSumExpression(): string
    {
        return "SUM(CASE WHEN coa.account_type = 'revenue' THEN jel.credit - jel.debit ELSE jel.debit - jel.credit END)";
    }
}
