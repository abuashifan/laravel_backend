<?php

namespace App\Modules\Reports\Services;

use App\Shared\Reports\Data\BalanceSheetFilter;
use App\Shared\Reports\Data\ProfitLossFilter;

/**
 * Laporan Multi-Periode (Fase 10, opsi (a): backend menerima periods[]).
 *
 * Untuk tiap periode, service ini MEMANGGIL ULANG service single-period existing
 * (ProfitLossService / BalanceSheetService) lalu menggabungkan hasilnya menjadi
 * kolom-per-periode yang di-align per akun. Karena tiap kolom diturunkan dari
 * laporan single-period yang sama, nilai kolom DIJAMIN identik dengan laporan
 * 1-periode untuk periode itu (sanity DoD Fase 10).
 */
class MultiPeriodReportService
{
    public function __construct(
        private readonly ProfitLossService $profitLossService,
        private readonly BalanceSheetService $balanceSheetService,
    ) {}

    /**
     * @param  list<array{start_date:string, end_date:string, label?:string|null}>  $periods
     * @param  array{department_id?:int|null, project_id?:int|null}  $dimensions
     * @return array<string,mixed>
     */
    public function profitLoss(array $periods, array $dimensions = []): array
    {
        $deptId = ! empty($dimensions['department_id']) ? (int) $dimensions['department_id'] : null;
        $projId = ! empty($dimensions['project_id']) ? (int) $dimensions['project_id'] : null;

        $perPeriod = [];
        $summary = [];
        foreach ($periods as $p) {
            $result = $this->profitLossService->getProfitLoss(new ProfitLossFilter(
                startDate: $p['start_date'],
                endDate: $p['end_date'],
                departmentId: $deptId,
                projectId: $projId,
                includeZeroBalance: false,
                groupBy: 'account_type',
            ));
            $perPeriod[] = $result['sections'] ?? [];
            $summary[] = [
                'total_revenue' => (float) ($result['totals']['total_revenue'] ?? 0),
                'total_expense' => (float) ($result['totals']['total_expense'] ?? 0),
                'net_profit_or_loss' => (float) ($result['totals']['net_profit_or_loss'] ?? 0),
            ];
        }

        return [
            'valid' => true,
            'report_type' => 'profit_loss',
            'periods' => $this->normalizePeriods($periods),
            'sections' => $this->mergeSections($perPeriod, count($periods)),
            'summary_totals' => $summary,
        ];
    }

    /**
     * @param  list<array{start_date:string, end_date:string, label?:string|null}>  $periods
     * @param  array{department_id?:int|null, project_id?:int|null}  $dimensions
     * @return array<string,mixed>
     */
    public function balanceSheet(array $periods, array $dimensions = []): array
    {
        $deptId = ! empty($dimensions['department_id']) ? (int) $dimensions['department_id'] : null;
        $projId = ! empty($dimensions['project_id']) ? (int) $dimensions['project_id'] : null;

        $perPeriod = [];
        $summary = [];
        foreach ($periods as $p) {
            // Neraca bersifat point-in-time → pakai end_date sebagai as_of_date.
            $result = $this->balanceSheetService->getBalanceSheet(new BalanceSheetFilter(
                asOfDate: $p['end_date'],
                departmentId: $deptId,
                projectId: $projId,
                includeZeroBalance: false,
                groupBy: 'account_type',
            ));
            $perPeriod[] = $result['sections'] ?? [];
            $summary[] = [
                'total_assets' => (float) ($result['totals']['total_assets'] ?? 0),
                'total_liabilities' => (float) ($result['totals']['total_liabilities'] ?? 0),
                'total_equity' => (float) ($result['totals']['total_equity'] ?? 0),
                'total_liabilities_and_equity' => (float) ($result['totals']['total_liabilities_and_equity'] ?? 0),
                'current_year_profit_or_loss' => (float) ($result['totals']['current_year_profit_or_loss'] ?? 0),
                'is_balanced' => (bool) ($result['totals']['is_balanced'] ?? false),
            ];
        }

        return [
            'valid' => true,
            'report_type' => 'balance_sheet',
            'periods' => $this->normalizePeriods($periods),
            'sections' => $this->mergeSections($perPeriod, count($periods)),
            'summary_totals' => $summary,
        ];
    }

    /**
     * Gabungkan sections[] dari beberapa periode menjadi baris-baris dengan values[] per kolom.
     *
     * @param  list<list<array<string,mixed>>>  $perPeriodSections  sections[] untuk tiap periode
     * @return list<array<string,mixed>>
     */
    private function mergeSections(array $perPeriodSections, int $periodCount): array
    {
        /** @var array<string,array{key:string,label:string,order:int,rows:array<string,array<string,mixed>>,totals:array<int,float>}> $sectionMap */
        $sectionMap = [];
        $sectionOrder = 0;

        foreach ($perPeriodSections as $periodIndex => $sections) {
            foreach ($sections as $section) {
                $sectionKey = (string) ($section['key'] ?? 'section');
                if (! isset($sectionMap[$sectionKey])) {
                    $sectionMap[$sectionKey] = [
                        'key' => $sectionKey,
                        'label' => (string) ($section['label'] ?? ucfirst($sectionKey)),
                        'order' => $sectionOrder++,
                        'rows' => [],
                        'totals' => array_fill(0, $periodCount, 0.0),
                    ];
                }

                $sectionMap[$sectionKey]['totals'][$periodIndex] = (float) ($section['total'] ?? 0);

                foreach (($section['accounts'] ?? []) as $acc) {
                    // Baris sintetis (mis. Laba/Rugi Tahun Berjalan) punya account_id null → key by nama.
                    $accountId = $acc['account_id'] ?? null;
                    $rowKey = $accountId !== null ? 'a'.$accountId : 'syn:'.($acc['account_name'] ?? '');

                    if (! isset($sectionMap[$sectionKey]['rows'][$rowKey])) {
                        $sectionMap[$sectionKey]['rows'][$rowKey] = [
                            'account_id' => $accountId !== null ? (int) $accountId : null,
                            'account_code' => $acc['account_code'] ?? null,
                            'account_name' => (string) ($acc['account_name'] ?? ''),
                            'account_type' => $acc['account_type'] ?? null,
                            'values' => array_fill(0, $periodCount, 0.0),
                        ];
                    }

                    $sectionMap[$sectionKey]['rows'][$rowKey]['values'][$periodIndex] = (float) ($acc['amount'] ?? 0);
                }
            }
        }

        // Urutkan section sesuai kemunculan; keluarkan rows sebagai list.
        $sections = array_values($sectionMap);
        usort($sections, fn ($a, $b) => $a['order'] <=> $b['order']);

        return array_map(fn ($s) => [
            'key' => $s['key'],
            'label' => $s['label'],
            'rows' => array_values($s['rows']),
            'totals' => array_map(fn ($v) => round((float) $v, 2), $s['totals']),
        ], $sections);
    }

    /**
     * @param  list<array{start_date:string, end_date:string, label?:string|null}>  $periods
     * @return list<array{label:string, start_date:string, end_date:string}>
     */
    private function normalizePeriods(array $periods): array
    {
        return array_values(array_map(fn ($p, $i) => [
            'label' => (string) ($p['label'] ?? ('Periode '.($i + 1))),
            'start_date' => (string) $p['start_date'],
            'end_date' => (string) $p['end_date'],
        ], $periods, array_keys($periods)));
    }
}
