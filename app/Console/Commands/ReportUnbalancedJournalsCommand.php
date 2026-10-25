<?php

namespace App\Console\Commands;

use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\TenantConnectionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Laporan baca-saja: jurnal posted yang total debit ≠ kredit, per tenant.
 * Dijalankan saat container start (docker/entrypoint.d) karena paket Render
 * tidak punya Shell -- hasilnya dibaca di log deploy. Tidak mengubah data;
 * koreksinya diputuskan manusia setelah melihat daftarnya.
 *
 * Latar: retur penjualan manual sebelum perbaikan ac6ae47 bisa membentuk
 * jurnal tidak seimbang.
 */
class ReportUnbalancedJournalsCommand extends Command
{
    protected $signature = 'journal:report-unbalanced {--company= : Hanya satu company_id}';

    protected $description = 'Laporkan jurnal posted yang debit dan kreditnya tidak seimbang (baca-saja)';

    public function handle(TenantConnectionManager $connectionManager): int
    {
        $tenants = TenantDatabase::query()
            ->where('status', 'active')
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', (int) $id))
            ->orderBy('company_id')
            ->get();

        $total = 0;

        foreach ($tenants as $tenant) {
            try {
                $connectionManager->connect($tenant);

                $rows = DB::connection('tenant')->table('journal_entries as je')
                    ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
                    ->where('je.status', 'posted')
                    ->groupBy('je.id', 'je.journal_number', 'je.journal_date', 'je.source_type', 'je.source_id')
                    ->havingRaw('ABS(SUM(jel.debit) - SUM(jel.credit)) >= 0.01')
                    ->selectRaw('je.id, je.journal_number, je.journal_date, je.source_type, je.source_id, SUM(jel.debit) as debit, SUM(jel.credit) as credit')
                    ->orderBy('je.journal_date')
                    ->get();
            } catch (Throwable $e) {
                $this->warn("[SKIP] company {$tenant->company_id}: {$e->getMessage()}");

                continue;
            }

            foreach ($rows as $row) {
                $total++;
                $this->line(sprintf(
                    '[UNBALANCED] company=%d journal=%s date=%s source=%s#%s debit=%.2f credit=%.2f selisih=%.2f',
                    $tenant->company_id,
                    $row->journal_number,
                    $row->journal_date,
                    $row->source_type ?? '-',
                    $row->source_id ?? '-',
                    (float) $row->debit,
                    (float) $row->credit,
                    (float) $row->debit - (float) $row->credit,
                ));
            }
        }

        $this->info($total === 0
            ? 'Tidak ada jurnal posted yang tidak seimbang.'
            : "{$total} jurnal posted tidak seimbang -- periksa daftar di atas.");

        return self::SUCCESS;
    }
}
