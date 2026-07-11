<?php

namespace App\Modules\Reports\Services;

use Illuminate\Support\Facades\DB;

class JournalListReportService
{
    /**
     * Peta sumber tingkat-tinggi → source_module pada journal_entries.
     * 'general' = Jurnal Umum (jurnal manual). Sumber lain (cash_bank, inventory, dsb.)
     * hanya muncul pada mode 'all'.
     *
     * @var array<string,list<string>>
     */
    private const SOURCE_MODULE_MAP = [
        'sales' => ['sales'],
        'purchase' => ['purchase'],
        'general' => ['journal'],
    ];

    /**
     * Daftar jurnal (posted) per periode dengan total debit/kredit per jurnal (Fase 7 T7.2/T7.3).
     *
     * @param  array{start_date?: string|null, end_date?: string|null, source?: string|null}  $filters
     * @return array{rows: list<array<string,mixed>>, totals: array<string,mixed>, filter: array<string,mixed>}
     */
    public function getReport(array $filters): array
    {
        $source = $filters['source'] ?? 'all';
        $modules = self::SOURCE_MODULE_MAP[$source] ?? null;

        $query = DB::connection('tenant')->table('journal_entries as je')
            ->leftJoin('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->where('je.status', '=', 'posted')
            ->where('je.is_obsolete', '=', 0)
            ->when(
                $filters['start_date'] ?? null,
                fn ($q, $v) => $q->whereDate('je.journal_date', '>=', $v),
            )
            ->when(
                $filters['end_date'] ?? null,
                fn ($q, $v) => $q->whereDate('je.journal_date', '<=', $v),
            )
            ->when(
                $modules,
                fn ($q, $mods) => $q->whereIn('je.source_module', $mods),
            )
            ->groupBy('je.id', 'je.journal_number', 'je.journal_date', 'je.description', 'je.source_type', 'je.source_number', 'je.source_module')
            ->select([
                'je.id as journal_entry_id',
                'je.journal_number',
                'je.journal_date',
                'je.description',
                'je.source_type',
                'je.source_number',
                'je.source_module',
                DB::raw('COALESCE(SUM(jel.debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(jel.credit), 0) as total_credit'),
                DB::raw('COUNT(jel.id) as line_count'),
            ])
            ->orderByDesc('je.journal_date')
            ->orderByDesc('je.id');

        $rows = $query->get()->map(fn ($row) => [
            'journal_entry_id' => (int) $row->journal_entry_id,
            'journal_number' => (string) $row->journal_number,
            'journal_date' => (string) $row->journal_date,
            'description' => $row->description !== null ? (string) $row->description : null,
            'source_type' => $row->source_type !== null ? (string) $row->source_type : null,
            'source_number' => $row->source_number !== null ? (string) $row->source_number : null,
            'source_module' => $row->source_module !== null ? (string) $row->source_module : null,
            'total_debit' => (float) $row->total_debit,
            'total_credit' => (float) $row->total_credit,
            'line_count' => (int) $row->line_count,
        ])->values()->all();

        $totals = [
            'journal_count' => count($rows),
            'total_debit' => array_sum(array_column($rows, 'total_debit')),
            'total_credit' => array_sum(array_column($rows, 'total_credit')),
        ];

        $filter = [
            'start_date' => $filters['start_date'] ?? null,
            'end_date' => $filters['end_date'] ?? null,
            'source' => $modules !== null ? $source : 'all',
        ];

        return compact('rows', 'totals', 'filter');
    }
}
