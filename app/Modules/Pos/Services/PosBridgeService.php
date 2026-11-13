<?php

namespace App\Modules\Pos\Services;

use App\Modules\Accounting\Services\AnnualClosingGateService;
use App\Modules\Accounting\Services\PeriodLockService;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\Journal\Services\SystemJournalBuilder;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\Pos\Models\PosBridgeConfig;
use App\Modules\Pos\Models\PosBridgeDispatch;
use App\Modules\Pos\Models\PosShift;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\TenantConnectionManager;
use App\Shared\Tenant\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Jembatan Finlite Kasir -> Finlite Accounting (Fase 5).
 *
 * Prinsip (dokumen 08): arus data SATU ARAH dan IDEMPOTEN. POS mengirim
 * ringkasan shift sebagai jurnal sistem "Rekap Kasir"; Akuntansi tidak
 * mengubah data POS. Kiriman yang sama tidak pernah tercatat dua kali, dijaga
 * dua lapis: kunci unik (jenis, id) di `pos_bridge_dispatches`, dan
 * pemeriksaan jurnal bersumber yang sudah ada di tenant akuntansi.
 *
 * Kegagalan mengirim TIDAK PERNAH menggagalkan aksi kasir. Menutup shift
 * tetap berhasil; kirimannya ditandai gagal/ditahan dan bisa dikirim ulang.
 */
class PosBridgeService
{
    public const SOURCE_TYPE = 'pos_shift';

    public const SOURCE_MODULE = 'pos';

    /** Batas kiriman sekali jalan saat menyalakan/menyinkronkan, supaya request tidak kelamaan. */
    private const BATCH = 100;

    /** Kode akun usulan (template COA bawaan) untuk tiap kunci pemetaan. */
    private const SUGGESTED_CODES = [
        'cash' => ['1100'], 'bank' => ['1110'], 'qris' => ['1110'], 'receivable' => ['1120'],
        'revenue' => ['4100'], 'discount' => ['4120'], 'sales_return' => ['4110'], 'tax' => ['2120'],
        'cogs' => ['5100'], 'inventory' => ['1131', '1130'],
    ];

    /** Jenis akun yang masuk akal per kunci. */
    private const ALLOWED_TYPES = [
        'cash' => ['asset'], 'bank' => ['asset'], 'qris' => ['asset'], 'receivable' => ['asset'],
        'revenue' => ['revenue'], 'discount' => ['revenue', 'expense'], 'sales_return' => ['revenue', 'expense'],
        'tax' => ['liability'], 'cogs' => ['expense'], 'inventory' => ['asset'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantConnectionManager $connections,
        private readonly PosBridgeJournalBuilder $builder,
        private readonly SystemJournalBuilder $journals,
        private readonly PeriodLockService $periodLocks,
        private readonly AnnualClosingGateService $annualGate,
        private readonly PosClock $clock,
    ) {}

    // ---- Keadaan jembatan ----

    /** Perusahaan harus punya tenant akuntansi aktif selain tenant POS ini. */
    public function accountingTenant(): ?TenantDatabase
    {
        $company = $this->context->company();

        // `TenantDatabase::query()` hanya melihat tenant akuntansi (global scope).
        return $company ? TenantDatabase::query()->where('company_id', $company->id)->where('status', 'active')->first() : null;
    }

    public function config(): PosBridgeConfig
    {
        return PosBridgeConfig::query()->first() ?? new PosBridgeConfig(['enabled' => false, 'post_cogs' => false, 'account_map' => []]);
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        $config = $this->config();
        $eligible = $this->accountingTenant() !== null;

        return [
            'eligible' => $eligible,
            'reason' => $eligible ? null : 'Toko ini belum memakai Finlite Accounting. Aktifkan Finlite Accounting dulu untuk menghubungkan kasir ke pembukuan.',
            'enabled' => (bool) $config->enabled,
            'start_date' => $config->start_date?->toDateString(),
            'post_cogs' => (bool) $config->post_cogs,
            'account_map' => (array) ($config->account_map ?? []),
            'map_keys' => collect(PosBridgeJournalBuilder::MAP_KEYS)->map(fn (string $label, string $key) => [
                'key' => $key, 'label' => $label, 'required' => in_array($key, PosBridgeJournalBuilder::REQUIRED_KEYS, true),
            ])->values()->all(),
            'counts' => collect(PosBridgeDispatch::STATUSES)
                ->mapWithKeys(fn (string $status) => [$status => PosBridgeDispatch::query()->where('status', $status)->count()])
                ->all(),
            'connected_at' => $config->connected_at?->toISOString(),
            'disconnected_at' => $config->disconnected_at?->toISOString(),
        ];
    }

    /**
     * Akun akuntansi yang boleh dipilih (daun, aktif) dan usulan pemetaan
     * dari kode akun template bawaan.
     *
     * @return array{accounts: list<array<string,mixed>>, suggestions: array<string,int>}
     */
    public function accountChoices(): array
    {
        $this->connectAccounting();
        $accounts = ChartOfAccount::query()
            ->where('is_active', true)->whereDoesntHave('children')
            ->orderBy('account_code')
            ->get(['id', 'account_code', 'account_name', 'account_type']);

        $byCode = $accounts->keyBy('account_code');
        $suggestions = [];
        foreach (self::SUGGESTED_CODES as $key => $codes) {
            foreach ($codes as $code) {
                $account = $byCode->get($code);
                if ($account && in_array($account->account_type, self::ALLOWED_TYPES[$key], true)) {
                    $suggestions[$key] = (int) $account->id;
                    break;
                }
            }
        }

        return [
            'accounts' => $accounts->map(fn (ChartOfAccount $account) => [
                'id' => (int) $account->id, 'code' => $account->account_code, 'name' => $account->account_name, 'type' => $account->account_type,
            ])->values()->all(),
            'suggestions' => $suggestions,
        ];
    }

    // ---- Pengaturan ----

    /**
     * Simpan pengaturan. Menyalakan jembatan menuntut pemetaan wajib lengkap dan
     * tanggal mulai; mematikannya hanya menghentikan kiriman baru.
     *
     * @param  array{enabled?:bool, start_date?:?string, post_cogs?:bool, account_map?:array<string,mixed>}  $data
     */
    public function save(User $user, array $data): PosBridgeConfig
    {
        if (! $this->accountingTenant()) {
            throw ApiException::make('BRIDGE_NOT_AVAILABLE', 'Toko ini belum memakai Finlite Accounting.', 422);
        }
        $this->connectAccounting();

        $config = PosBridgeConfig::query()->first() ?? new PosBridgeConfig(['enabled' => false, 'post_cogs' => false]);
        $map = array_key_exists('account_map', $data)
            ? $this->cleanMap((array) $data['account_map'])
            : (array) ($config->account_map ?? []);
        $postCogs = (bool) ($data['post_cogs'] ?? $config->post_cogs);
        $enabled = (bool) ($data['enabled'] ?? $config->enabled);
        $startDate = array_key_exists('start_date', $data) ? ($data['start_date'] ?: null) : $config->start_date?->toDateString();

        if ($enabled) {
            $missing = [];
            $required = array_merge(PosBridgeJournalBuilder::REQUIRED_KEYS, $postCogs ? ['cogs', 'inventory'] : []);
            foreach ($required as $key) {
                if (empty($map[$key])) {
                    $missing[] = PosBridgeJournalBuilder::MAP_KEYS[$key];
                }
            }
            if ($missing !== []) {
                throw ApiException::make('BRIDGE_MAPPING_INCOMPLETE', 'Pemetaan akun belum lengkap: '.implode(', ', $missing).'.', 422, ['account_map' => ['Lengkapi: '.implode(', ', $missing).'.']]);
            }
            $startDate ??= $this->clock->today();
        }

        $wasEnabled = (bool) $config->enabled;
        $config->fill([
            'enabled' => $enabled,
            'start_date' => $startDate,
            'post_cogs' => $postCogs,
            'account_map' => $map,
        ]);
        if ($enabled && ! $wasEnabled) {
            $config->connected_by = $user->id;
            $config->connected_at = now();
            $config->disconnected_at = null;
        } elseif (! $enabled && $wasEnabled) {
            $config->disconnected_at = now();
        }
        $config->save();

        return $config;
    }

    /**
     * @param  array<string,mixed>  $map
     * @return array<string,int>
     */
    private function cleanMap(array $map): array
    {
        $clean = [];
        foreach (PosBridgeJournalBuilder::MAP_KEYS as $key => $label) {
            $id = $map[$key] ?? null;
            if ($id === null || $id === '' || $id === 0) {
                continue;
            }
            $account = ChartOfAccount::query()->find((int) $id);
            if (! $account || ! $account->is_active) {
                throw ApiException::make('BRIDGE_ACCOUNT_INVALID', "Akun untuk \"{$label}\" tidak ditemukan atau tidak aktif.", 422, ['account_map.'.$key => ['Akun tidak valid.']]);
            }
            if ($account->children()->exists()) {
                throw ApiException::make('BRIDGE_ACCOUNT_INVALID', "Akun untuk \"{$label}\" adalah akun induk. Pilih akun terbawah.", 422, ['account_map.'.$key => ['Pilih akun terbawah.']]);
            }
            if (! in_array($account->account_type, self::ALLOWED_TYPES[$key], true)) {
                throw ApiException::make('BRIDGE_ACCOUNT_INVALID', "Akun untuk \"{$label}\" harus bertipe ".implode(' atau ', self::ALLOWED_TYPES[$key]).'.', 422, ['account_map.'.$key => ['Jenis akun tidak sesuai.']]);
            }
            $clean[$key] = (int) $account->id;
        }

        return $clean;
    }

    // ---- Antrean dan pengiriman ----

    /**
     * Dipanggil setelah shift ditutup. Tidak pernah melempar: kegagalan hanya
     * ditandai pada kiriman, tidak menggagalkan penutupan shift.
     */
    public function onShiftClosed(PosShift $shift): void
    {
        try {
            $config = $this->config();
            if (! $config->enabled || ! $this->accountingTenant()) {
                return;
            }
            $dispatch = $this->queueShift($shift, $config);
            if ($dispatch) {
                $this->send($dispatch);
            }
        } catch (Throwable $e) {
            Log::warning('Jembatan kasir: gagal memproses shift '.$shift->id.': '.$e->getMessage());
        }
    }

    /** Buat baris kiriman untuk shift tertutup yang masuk rentang tanggal mulai. */
    public function queueShift(PosShift $shift, ?PosBridgeConfig $config = null): ?PosBridgeDispatch
    {
        $config ??= $this->config();
        if ($shift->status !== 'closed' || ! $shift->closed_at) {
            return null;
        }
        $date = $this->clock->format($shift->closed_at, 'Y-m-d');
        if ($config->start_date && $date < $config->start_date->toDateString()) {
            return null;
        }

        return PosBridgeDispatch::query()->firstOrCreate(
            ['kind' => 'shift', 'source_id' => $shift->id],
            ['source_label' => 'Shift #'.$shift->id.' - '.($shift->user_name ?: 'kasir'), 'entry_date' => $date, 'status' => 'pending'],
        );
    }

    /**
     * Antrekan shift tertutup yang belum punya kiriman, lalu kirim yang belum
     * terkirim. Aman dipanggil berulang.
     *
     * @return array{queued:int, sent:int, failed:int, held:int, skipped:int, remaining:int}
     */
    public function sync(): array
    {
        $config = $this->config();
        $result = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'held' => 0, 'skipped' => 0, 'remaining' => 0];
        if (! $config->enabled || ! $this->accountingTenant()) {
            return $result;
        }

        $known = PosBridgeDispatch::query()->where('kind', 'shift')->pluck('source_id')->all();
        PosShift::query()->where('status', 'closed')->whereNotNull('closed_at')
            ->when($known !== [], fn ($query) => $query->whereNotIn('id', $known))
            ->orderBy('closed_at')->limit(self::BATCH * 5)->get()
            ->each(function (PosShift $shift) use ($config, &$result) {
                if ($this->queueShift($shift, $config)) {
                    $result['queued']++;
                }
            });

        $open = PosBridgeDispatch::query()->whereIn('status', ['pending', 'failed', 'held'])->orderBy('entry_date')->orderBy('id');
        foreach ((clone $open)->limit(self::BATCH)->get() as $dispatch) {
            $status = $this->send($dispatch)->status;
            $result[$status] = ($result[$status] ?? 0) + 1;
        }
        $result['remaining'] = (clone $open)->count();

        return $result;
    }

    /** Kirim satu shift sebagai jurnal. Hasilnya tersimpan di baris kiriman. */
    public function send(PosBridgeDispatch $dispatch): PosBridgeDispatch
    {
        if (in_array($dispatch->status, ['sent', 'skipped'], true)) {
            return $dispatch;
        }

        $dispatch->forceFill(['attempts' => $dispatch->attempts + 1])->save();

        try {
            $company = $this->context->company();
            $this->connectAccounting();

            // Lapis kedua idempotensi: jurnal bersumber shift ini sudah ada di akuntansi.
            $existing = JournalEntry::withTrashed()->forSource(self::SOURCE_TYPE, $dispatch->source_id)->forSourceModule(self::SOURCE_MODULE)->first();
            if ($existing) {
                return $this->mark($dispatch, 'sent', ['journal_id' => $existing->id, 'journal_number' => $existing->journal_number, 'error' => null, 'sent_at' => now()]);
            }

            $shift = PosShift::query()->findOrFail($dispatch->source_id);
            $config = $this->config();
            $built = $this->builder->forShift($shift, (array) $config->account_map, (bool) $config->post_cogs);
            $dispatch->summary = $built['summary'];

            if ($built['empty']) {
                return $this->mark($dispatch, 'skipped', ['error' => 'Tidak ada transaksi di shift ini.', 'sent_at' => now()]);
            }

            $date = $dispatch->entry_date->toDateString();
            if ($reason = $this->heldReason($company, $date)) {
                return $this->mark($dispatch, 'held', ['error' => 'Ditahan: '.$reason]);
            }

            $journal = $this->journals->create([
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $dispatch->source_id,
                'source_number' => $dispatch->source_label,
                'source_module' => self::SOURCE_MODULE,
                'journal_date' => $date,
                'description' => 'Rekap Kasir '.$date.' - '.$dispatch->source_label,
            ], $built['lines']);

            return $this->mark($dispatch, 'sent', ['journal_id' => $journal->id, 'journal_number' => $journal->journal_number, 'error' => null, 'sent_at' => now()]);
        } catch (Throwable $e) {
            return $this->mark($dispatch, 'failed', ['error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    /** Kirim ulang satu kiriman yang gagal atau ditahan. */
    public function retry(PosBridgeDispatch $dispatch): PosBridgeDispatch
    {
        if (! $this->config()->enabled) {
            throw ApiException::make('BRIDGE_DISABLED', 'Jembatan sedang diputus. Sambungkan dulu untuk mengirim.', 422);
        }

        return $this->send($dispatch);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function mark(PosBridgeDispatch $dispatch, string $status, array $attributes): PosBridgeDispatch
    {
        $dispatch->forceFill(array_merge($attributes, ['status' => $status]))->save();

        return $dispatch->refresh();
    }

    /**
     * Alasan kiriman harus ditahan, atau null kalau boleh masuk.
     *
     * Hanya kunci periode dan tahun fiskal yang dicek -- bukan seluruh
     * `TransactionDateGuardService`. Aturan tanggal mundur/masa depan di sana
     * dibuat untuk input manual dan memakai tanggal server (UTC): shift yang
     * ditutup antara 00.00-07.00 WIB punya tanggal toko yang sudah "besok"
     * menurut server, jadi akan ditolak sebagai transaksi masa depan.
     */
    private function heldReason(Company $company, string $date): ?string
    {
        if ($this->periodLocks->isDateReadOnly($company, $date)) {
            return 'periode akuntansi untuk tanggal '.$date.' sudah terkunci. Buka periodenya lalu kirim ulang.';
        }
        if ($blocking = $this->annualGate->blockingFiscalYear($company, $date)) {
            return 'tahun fiskal '.$blocking->year.' belum ditutup. Tutup tahun buku sebelumnya lalu kirim ulang.';
        }

        return null;
    }

    private bool $accountingConnected = false;

    private function connectAccounting(): void
    {
        if ($this->accountingConnected) {
            return;
        }
        $tenant = $this->accountingTenant();
        if (! $tenant) {
            throw ApiException::make('BRIDGE_NOT_AVAILABLE', 'Toko ini belum memakai Finlite Accounting.', 422);
        }
        $this->connections->connect($tenant);
        $this->accountingConnected = true;
    }
}
