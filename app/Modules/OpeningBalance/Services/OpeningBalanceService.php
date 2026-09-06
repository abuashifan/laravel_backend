<?php

namespace App\Modules\OpeningBalance\Services;

use App\Modules\FixedAssets\Services\FixedAssetService;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Shared\Audit\AuditLogService;
use App\Shared\DocumentNumbering\DocumentNumberService;
use App\Shared\DocumentNumbering\DocumentType;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\CompanyModuleSetting;
use App\Shared\Models\CompanySetupState;
use App\Shared\Models\FiscalYear;
use App\Shared\Tenant\TenantContext;
use App\Shared\TransactionLifecycle\TransactionVoidEffectService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo awal — Fase 8.
 *
 * ── Bentuknya, dalam tiga kalimat ───────────────────────────────────────────
 *
 * 1. Impor saldo awal **mengisi saldo akun**: satu berkas neraca saldo → satu
 *    jurnal, dan selisih seluruh berkas jatuh ke **akun perantara**
 *    (`opening_balance.clearing`) sebagai satu baris lawan. Jurnalnya seimbang
 *    menurut konstruksi — tidak ada jalan bagi user untuk menghasilkan selisih.
 * 2. Impor aset tetap **mendaftar kartu aset**, nol jurnal. Boleh sebagian,
 *    boleh berkali-kali.
 * 3. Keduanya tidak saling tahu. Urutan bebas, tidak ada prasyarat, tidak ada
 *    akun yang ditolak.
 *
 * Saldo akun perantara adalah **ekuitas pembuka yang belum diakui**: aset
 * dikurangi liabilitas, dihitung sistem, bukan ditebak user. Ia ditutup ke
 * `opening_balance.equity` lewat satu langkah eksplisit di akhir.
 *
 * ── Kenapa modul batch dibongkar ────────────────────────────────────────────
 *
 * Sampai Fase 7, saldo awal adalah dokumen tersendiri: satu batch dengan enam
 * status, editor baris, Validasi → Posting → Kunci → Buka Kembali. Baris harga
 * perolehan aset tetap dihasilkan otomatis dari register, yang membuat urutan
 * impor jadi wajib dan berkas neraca saldo klien harus dipreteli lebih dulu.
 *
 * Semua itu hilang di sini. Jurnal pembuka adalah **jurnal biasa** dengan
 * `source_module = 'opening_balance'`; membatalkannya = mem-void jurnalnya,
 * satu per satu, kapan saja. Tidak ada yang perlu di-reopen karena tidak ada
 * dokumen yang mengurung mereka.
 */
class OpeningBalanceService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly AuditLogService $auditLogService,
        private readonly TransactionVoidEffectService $voidEffectService,
        private readonly FixedAssetService $fixedAssetService,
    ) {}

    // ── Tanggal saldo awal ──────────────────────────────────────────────

    /**
     * Satu tanggal per perusahaan, dipakai setiap jurnal pembuka.
     *
     * Ia harus pasti **sejak awal**, bukan saat sebuah dokumen diposting:
     * `FixedAssetService::fillAutoAccumulatedDepreciation()` menghitung
     * akumulasi penyusutan per tanggal ini, dan aset boleh diimpor kapan saja —
     * termasuk sebelum berkas saldo awal pertama masuk.
     *
     * Urutan tebakan saat user belum menetapkannya: awal tahun fiskal aktif,
     * lalu awal tahun berjalan. Tidak pernah "hari ini" — saldo awal bertanggal
     * tengah bulan hampir selalu salah.
     */
    public function openingDate(): string
    {
        $state = $this->setupState();
        if ($state?->opening_date) {
            return $state->opening_date->toDateString();
        }

        $start = FiscalYear::query()
            ->where('company_id', $this->company()->id)
            ->where('is_active', true)
            ->orderByDesc('start_date')
            ->value('start_date');

        if ($start) {
            return $start instanceof \DateTimeInterface ? $start->format('Y-m-d') : (string) $start;
        }

        return CarbonImmutable::now()->startOfYear()->toDateString();
    }

    public function setOpeningDate(string $date): string
    {
        $parsed = CarbonImmutable::parse($date)->toDateString();

        if ($this->openingJournalQuery()->exists()) {
            throw ApiException::make(
                'OPENING_BALANCE_DATE_LOCKED',
                'Tanggal saldo awal tidak bisa diubah karena sudah ada jurnal pembuka. Batalkan jurnalnya dulu.',
                422
            );
        }

        $company = $this->company();
        $state = CompanySetupState::query()->firstOrCreate(['company_id' => $company->id]);
        $state->forceFill(['opening_date' => $parsed])->save();

        $this->audit('opening_balance.date_set', 'Opening balance date set.', ['opening_date' => $parsed]);

        return $parsed;
    }

    // ── Akun perantara ──────────────────────────────────────────────────

    public function clearingAccountId(): int
    {
        return $this->mappingAccount('opening_balance.clearing');
    }

    public function equityAccountId(): int
    {
        return $this->mappingAccount('opening_balance.equity');
    }

    /**
     * Versi yang boleh gagal, untuk pembacaan.
     *
     * Papan pemantau dan wizard Setup memanggil `status()` jauh sebelum Daftar
     * Akun diterapkan — di titik itu pemetaan perantara memang belum ada, dan
     * melempar exception akan membuat langkah pertama wizard mati total. Yang
     * WAJIB punya akunnya cuma yang benar-benar memposting.
     */
    private function optionalMappingAccount(string $key): ?int
    {
        $accountId = AccountMapping::query()
            ->where('mapping_key', $key)
            ->where('is_active', true)
            ->value('account_id');

        return $accountId ? (int) $accountId : null;
    }

    /**
     * Saldo akun perantara. Positif = saldo debit, negatif = saldo kredit.
     *
     * Nol berarti neraca pembuka selesai: seluruh yang diimpor sudah diakui
     * sebagai ekuitas.
     */
    public function clearingBalance(): float
    {
        $accountId = $this->optionalMappingAccount('opening_balance.clearing');

        return $accountId === null ? 0.0 : $this->accountBalance($accountId);
    }

    // ── Posting ─────────────────────────────────────────────────────────

    /**
     * Posting satu jurnal pembuka dari baris yang sudah tervalidasi.
     *
     * Baris lawan ke akun perantara **dihitung di sini**, bukan diminta dari
     * pemanggil: itulah satu-satunya alasan impor saldo awal tidak pernah bisa
     * menghasilkan jurnal yang tidak seimbang.
     *
     * Jurnalnya dibuat langsung, tidak lewat `JournalEntryService::createManual()`
     * — method itu menolak akun kontrol (piutang, utang, persediaan) yang justru
     * WAJIB terisi di neraca pembuka.
     *
     * @param  list<array{account_id:int, debit?:float, credit?:float, description?:?string, metadata?:array}>  $lines
     */
    public function postOpeningJournal(array $lines, array $options = []): JournalEntry
    {
        if ($lines === []) {
            throw ApiException::make('OPENING_BALANCE_NO_LINES', 'Tidak ada baris untuk diposting.', 422);
        }

        $openingDate = $this->openingDate();
        $company = $this->company();

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($lines as $line) {
            $totalDebit += round((float) ($line['debit'] ?? 0), 2);
            $totalCredit += round((float) ($line['credit'] ?? 0), 2);
        }

        $difference = round($totalDebit - $totalCredit, 2);
        if (abs($difference) >= 0.01) {
            $lines[] = [
                'account_id' => $this->clearingAccountId(),
                'debit' => $difference < 0 ? abs($difference) : 0.0,
                'credit' => $difference > 0 ? $difference : 0.0,
                'description' => 'Perantara saldo awal',
                'metadata' => ['opening_balance_role' => 'clearing'],
            ];
        }

        // Satu baris tanpa lawan berarti berkasnya sudah seimbang sendiri —
        // sah, dan tidak butuh baris perantara. Tapi jurnal satu baris tidak
        // pernah sah.
        if (count($lines) < 2) {
            throw ApiException::make('OPENING_BALANCE_SINGLE_LINE', 'Jurnal saldo awal memerlukan minimal dua baris.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($lines, $openingDate, $company, $options) {
            $journalNumber = $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, $openingDate);
            $description = trim((string) ($options['description'] ?? '')) ?: 'Saldo awal';

            $journal = JournalEntry::query()->create([
                'journal_number' => $journalNumber,
                'journal_date' => $openingDate,
                'description' => $description,
                'status' => 'posted',
                'revision_no' => 1,
                'source_type' => 'opening_balance',
                'source_id' => null,
                'source_number' => $journalNumber,
                'source_revision' => 1,
                'source_module' => 'opening_balance',
                'source_batch_id' => null,
                'is_system_generated' => true,
                'is_obsolete' => false,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
                'posted_by' => auth()->id(),
                'posted_at' => now(),
                'metadata' => $options['metadata'] ?? null,
            ]);

            $rows = [];
            foreach (array_values($lines) as $index => $line) {
                $rows[] = [
                    'account_id' => (int) $line['account_id'],
                    'description' => $line['description'] ?? 'Saldo awal',
                    'debit' => round((float) ($line['debit'] ?? 0), 2),
                    'credit' => round((float) ($line['credit'] ?? 0), 2),
                    'line_order' => $index + 1,
                    'metadata' => $line['metadata'] ?? null,
                ];
            }
            $journal->lines()->createMany($rows);

            $this->audit('opening_balance.journal_posted', 'Opening balance journal posted.', [
                'journal_entry_id' => $journal->id,
                'journal_number' => $journal->journal_number,
                'line_count' => count($rows),
            ]);

            return $journal->refresh()->load('lines');
        });
    }

    /**
     * Tutup saldo perantara ke ekuitas.
     *
     * Inilah langkah yang menjawab "berapa modal pemilik / laba ditahan": ia
     * tidak diketik, ia sisa perantara — aset dikurangi liabilitas, apa adanya.
     *
     * `$targets` opsional untuk memecahnya ke beberapa akun ekuitas (mis.
     * sebagian Modal Pemilik, sebagian Laba Ditahan) bagi klien yang memang tahu
     * angkanya. Tanpa itu, seluruhnya ke `opening_balance.equity`.
     *
     * @param  list<array{account_id:int, amount:float}>|null  $targets
     */
    public function closeClearing(?array $targets = null, ?string $description = null): JournalEntry
    {
        $balance = $this->clearingBalance();

        if (abs($balance) < 0.01) {
            throw ApiException::make(
                'OPENING_BALANCE_CLEARING_ALREADY_ZERO',
                'Saldo akun perantara sudah nol — tidak ada yang perlu ditutup.',
                422
            );
        }

        $clearingId = $this->clearingAccountId();
        $splits = $this->resolveCloseTargets($targets, abs($balance));

        // Perantara dibalik: saldo debit ditutup dengan kredit, dan sebaliknya.
        $lines = [[
            'account_id' => $clearingId,
            'debit' => $balance < 0 ? abs($balance) : 0.0,
            'credit' => $balance > 0 ? $balance : 0.0,
            'description' => 'Penutupan perantara saldo awal',
            'metadata' => ['opening_balance_role' => 'clearing_close'],
        ]];

        foreach ($splits as $split) {
            $lines[] = [
                'account_id' => (int) $split['account_id'],
                'debit' => $balance < 0 ? 0.0 : (float) $split['amount'],
                'credit' => $balance < 0 ? (float) $split['amount'] : 0.0,
                'description' => 'Ekuitas pembuka',
                'metadata' => ['opening_balance_role' => 'equity'],
            ];
        }

        $journal = $this->postOpeningJournal($lines, [
            'description' => $description ?: 'Penutupan saldo awal ke ekuitas',
            'metadata' => ['opening_balance_role' => 'clearing_close'],
        ]);

        $this->audit('opening_balance.clearing_closed', 'Opening balance clearing closed to equity.', [
            'journal_entry_id' => $journal->id,
            'amount' => abs($balance),
        ]);

        return $journal;
    }

    /**
     * @param  list<array{account_id:int, amount:float}>|null  $targets
     * @return list<array{account_id:int, amount:float}>
     */
    private function resolveCloseTargets(?array $targets, float $amount): array
    {
        if ($targets === null || $targets === []) {
            return [['account_id' => $this->equityAccountId(), 'amount' => $amount]];
        }

        $total = 0.0;
        foreach ($targets as $target) {
            $total += round((float) $target['amount'], 2);
        }

        if (abs(round($total - $amount, 2)) >= 0.01) {
            throw ApiException::make(
                'OPENING_BALANCE_CLOSE_SPLIT_MISMATCH',
                sprintf('Total pemecahan (%s) tidak sama dengan saldo perantara (%s).', number_format($total, 2, ',', '.'), number_format($amount, 2, ',', '.')),
                422
            );
        }

        foreach ($targets as $target) {
            $account = ChartOfAccount::query()->find((int) $target['account_id']);
            if (! $account instanceof ChartOfAccount || ! $account->is_active) {
                throw ApiException::make('OPENING_BALANCE_CLOSE_ACCOUNT_INVALID', 'Akun tujuan penutupan tidak ditemukan atau tidak aktif.', 422);
            }
            if ((string) $account->account_type !== 'equity') {
                throw ApiException::make('OPENING_BALANCE_CLOSE_ACCOUNT_INVALID', "Akun '{$account->account_code}' bukan akun ekuitas.", 422);
            }
        }

        return array_values(array_map(fn (array $t): array => [
            'account_id' => (int) $t['account_id'],
            'amount' => round((float) $t['amount'], 2),
        ], $targets));
    }

    // ── Pembatalan ──────────────────────────────────────────────────────

    /**
     * Batalkan satu jurnal pembuka. Tidak ada yang perlu di-reopen: tiap jurnal
     * berdiri sendiri, jadi membatalkan yang satu tidak menyentuh yang lain.
     */
    public function voidOpeningJournal(int $journalId, string $reason): void
    {
        $reason = $this->voidEffectService->requireReason($reason);

        $journal = JournalEntry::query()
            ->where('id', $journalId)
            ->where('source_module', 'opening_balance')
            ->first();

        if (! $journal instanceof JournalEntry) {
            throw ApiException::make('OPENING_BALANCE_JOURNAL_NOT_FOUND', 'Jurnal saldo awal tidak ditemukan.', 404);
        }

        if ((string) $journal->status === 'void') {
            return;
        }

        $this->voidEffectService->voidJournalById((int) $journal->id, $reason);

        $this->audit('opening_balance.journal_voided', 'Opening balance journal voided.', [
            'journal_entry_id' => $journal->id,
            'journal_number' => $journal->journal_number,
            'reason' => $reason,
        ]);
    }

    // ── Pembacaan ───────────────────────────────────────────────────────

    /**
     * @return Collection<int, JournalEntry>
     */
    public function openingJournals(): Collection
    {
        return $this->openingJournalQuery()
            ->with('lines.account')
            ->orderByDesc('id')
            ->get();
    }

    public function status(): array
    {
        $clearing = $this->clearingBalance();
        $journals = $this->openingJournals();
        $clearingAccountId = $this->optionalMappingAccount('opening_balance.clearing');
        $equityAccountId = $this->optionalMappingAccount('opening_balance.equity');
        $clearingAccount = $clearingAccountId === null ? null : ChartOfAccount::query()->find($clearingAccountId);
        $equityAccount = $equityAccountId === null ? null : ChartOfAccount::query()->find($equityAccountId);

        return [
            'opening_date' => $this->openingDate(),
            'opening_date_locked' => $journals->isNotEmpty(),
            // Selama pemetaannya belum ada, saldo awal belum bisa diimpor sama
            // sekali — dan itu keadaan yang normal di awal wizard, bukan galat.
            'ready' => $clearingAccount !== null && $equityAccount !== null,
            'clearing_account' => $clearingAccount ? [
                'id' => (int) $clearingAccount->id,
                'account_code' => $clearingAccount->account_code,
                'account_name' => $clearingAccount->account_name,
            ] : null,
            'equity_account' => $equityAccount ? [
                'id' => (int) $equityAccount->id,
                'account_code' => $equityAccount->account_code,
                'account_name' => $equityAccount->account_name,
            ] : null,
            'clearing_balance' => round($clearing, 2),
            'is_complete' => $journals->isNotEmpty() && abs($clearing) < 0.01,
            'journal_count' => $journals->count(),
            'journals' => $journals->map(fn (JournalEntry $j): array => [
                'id' => (int) $j->id,
                'journal_number' => $j->journal_number,
                'journal_date' => $j->journal_date?->toDateString(),
                'description' => $j->description,
                'status' => $j->status,
                'total_debit' => round((float) $j->lines->sum('debit'), 2),
                'line_count' => $j->lines->count(),
                'role' => ((array) ($j->metadata ?? []))['opening_balance_role'] ?? 'entry',
            ])->all(),
            'fixed_asset_reconciliation' => $this->fixedAssetReconciliation(),
        ];
    }

    /**
     * Register aset tetap dibanding saldo buku besarnya.
     *
     * Selisih di sini **bukan galat**. Tanah bisa berdiri di beberapa lokasi
     * sementara yang terdaftar baru satu; pendaftaran aset memang boleh
     * sebagian, dan dilanjutkan kapan saja. Yang dilakukan laporan ini cuma
     * memberitahu, supaya tidak ada yang lupa melanjutkannya.
     */
    public function fixedAssetReconciliation(): array
    {
        if (! $this->fixedAssetsEnabled() || ! Schema::connection('tenant')->hasTable('fixed_assets')) {
            return ['enabled' => false, 'asset_count' => 0, 'rows' => [], 'has_difference' => false];
        }

        $totals = $this->fixedAssetService->openingAssetTotals($this->openingDate());
        $rows = [];

        foreach (['cost_by_account' => 'cost', 'accumulated_by_account' => 'accumulated'] as $key => $kind) {
            foreach ((array) $totals[$key] as $accountId => $amount) {
                $rows[(int) $accountId] = [
                    'account_id' => (int) $accountId,
                    'kind' => $kind,
                    'register_amount' => round((float) $amount, 2),
                    'gl_amount' => 0.0,
                ];
            }
        }

        foreach ($rows as $accountId => $row) {
            $balance = $this->accountBalance((int) $accountId);
            $rows[$accountId]['gl_amount'] = round($row['kind'] === 'accumulated' ? -$balance : $balance, 2);
        }

        $out = [];
        foreach ($rows as $row) {
            $account = ChartOfAccount::query()->find($row['account_id']);
            $difference = round($row['gl_amount'] - $row['register_amount'], 2);
            $out[] = [
                'account_id' => $row['account_id'],
                'account_code' => $account?->account_code,
                'account_name' => $account?->account_name,
                'kind' => $row['kind'],
                'register_amount' => $row['register_amount'],
                'gl_amount' => $row['gl_amount'],
                'difference' => $difference,
            ];
        }

        return [
            'enabled' => true,
            'asset_count' => (int) $totals['count'],
            'rows' => $out,
            'has_difference' => collect($out)->contains(fn (array $r): bool => abs($r['difference']) >= 0.01),
        ];
    }

    // ── Internal ────────────────────────────────────────────────────────

    private function openingJournalQuery()
    {
        return JournalEntry::query()
            ->where('source_module', 'opening_balance')
            ->where('status', '!=', 'void');
    }

    /**
     * Saldo buku besar satu akun dari jurnal terposting. Positif = saldo debit.
     */
    private function accountBalance(int $accountId): float
    {
        $row = DB::connection('tenant')
            ->table('journal_entry_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.status', 'posted')
            ->where('l.account_id', $accountId)
            ->selectRaw('COALESCE(SUM(l.debit), 0) as total_debit, COALESCE(SUM(l.credit), 0) as total_credit')
            ->first();

        return round((float) ($row->total_debit ?? 0) - (float) ($row->total_credit ?? 0), 2);
    }

    private function mappingAccount(string $key): int
    {
        $accountId = AccountMapping::query()
            ->where('mapping_key', $key)
            ->where('is_active', true)
            ->value('account_id');

        if (! $accountId) {
            throw ApiException::make('ACCOUNT_MAPPING_MISSING', "Pemetaan akun [{$key}] belum diatur.", 422);
        }

        return (int) $accountId;
    }

    private function fixedAssetsEnabled(): bool
    {
        return (bool) CompanyModuleSetting::query()
            ->where('company_id', $this->company()->id)
            ->value('fixed_asset_enabled');
    }

    private function setupState(): ?CompanySetupState
    {
        return CompanySetupState::query()->where('company_id', $this->company()->id)->first();
    }

    private function company()
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        return $company;
    }

    private function audit(string $event, string $message, array $metadata = []): void
    {
        $this->auditLogService->logSuccess([
            'event' => $event,
            'module' => 'opening_balance',
            'action' => $event,
            'message' => $message,
            'record_type' => 'opening_balance',
            'record_id' => $metadata['journal_entry_id'] ?? null,
            'record_number' => $metadata['journal_number'] ?? null,
            'metadata' => array_merge(['user_id' => auth()->id()], $metadata),
        ], tenant: true);
    }
}
