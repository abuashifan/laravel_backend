<?php

namespace App\Modules\Pos\Services;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\Journal\Services\SystemJournalBuilder;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\Pos\Models\PosBridgeConfig;
use App\Modules\Pos\Models\PosBridgeDispatch;
use App\Modules\Pos\Models\PosPurchase;
use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Models\PosStockCount;
use App\Modules\Pos\Models\PosSupplierPayment;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\TenantConnectionManager;
use App\Shared\Tenant\TenantContext;
use App\Shared\TransactionLifecycle\TransactionDateGuardService;
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

    /**
     * Jenis kiriman -> `source_type` jurnal di akuntansi dan judul jurnalnya.
     * Stok masuk dan pembayaran hutang hanya dikirim bersama HPP (`post_cogs`).
     */
    public const KINDS = [
        'shift' => ['type' => 'pos_shift', 'title' => 'Rekap Kasir'],
        'purchase' => ['type' => 'pos_purchase', 'title' => 'Stok Masuk Kasir'],
        'payment' => ['type' => 'pos_supplier_payment', 'title' => 'Bayar Hutang Kasir'],
        'count' => ['type' => 'pos_stock_count', 'title' => 'Selisih Stok Kasir'],
    ];

    /** Batas kiriman sekali jalan saat menyalakan/menyinkronkan, supaya request tidak kelamaan. */
    private const BATCH = 100;

    /** Kode akun usulan (template COA bawaan) untuk tiap kunci pemetaan. */
    private const SUGGESTED_CODES = [
        'cash' => ['1100'], 'bank' => ['1110'], 'qris' => ['1110'], 'receivable' => ['1120'],
        'revenue' => ['4100'], 'discount' => ['4120'], 'sales_return' => ['4110'], 'tax' => ['2120'],
        'cogs' => ['5100'], 'inventory' => ['1131', '1130'], 'payable' => ['2100'], 'stock_variance' => ['5120'],
    ];

    /** Jenis akun yang masuk akal per kunci. */
    private const ALLOWED_TYPES = [
        'cash' => ['asset'], 'bank' => ['asset'], 'qris' => ['asset'], 'receivable' => ['asset'],
        'revenue' => ['revenue'], 'discount' => ['revenue', 'expense'], 'sales_return' => ['revenue', 'expense'],
        'tax' => ['liability'], 'cogs' => ['expense'], 'inventory' => ['asset'], 'payable' => ['liability'], 'stock_variance' => ['expense'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantConnectionManager $connections,
        private readonly PosBridgeJournalBuilder $builder,
        private readonly SystemJournalBuilder $journals,
        private readonly TransactionDateGuardService $dateGuard,
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
                'with_cogs' => in_array($key, PosBridgeJournalBuilder::REQUIRED_WITH_COGS, true),
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
            $required = array_merge(PosBridgeJournalBuilder::REQUIRED_KEYS, $postCogs ? PosBridgeJournalBuilder::REQUIRED_WITH_COGS : []);
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
        $this->safely('shift '.$shift->id, function (PosBridgeConfig $config) use ($shift) {
            return $this->queueShift($shift, $config);
        });
    }

    /** Stok masuk baru tersimpan. Hanya dikirim bila HPP/persediaan ikut dikirim. */
    public function onPurchaseCreated(PosPurchase $purchase): void
    {
        $this->safely('stok masuk '.$purchase->id, function (PosBridgeConfig $config) use ($purchase) {
            return $this->queuePurchase($purchase, $config);
        });
    }

    /** Pembayaran hutang pemasok baru tersimpan. */
    public function onSupplierPayment(PosSupplierPayment $payment): void
    {
        $this->safely('pembayaran hutang '.$payment->id, function (PosBridgeConfig $config) use ($payment) {
            return $this->queuePayment($payment, $config);
        });
    }

    /** Stok opname baru tersimpan. Hanya dikirim bila HPP/persediaan ikut dikirim. */
    public function onStockCountCreated(PosStockCount $count): void
    {
        $this->safely('stok opname '.$count->id, function (PosBridgeConfig $config) use ($count) {
            return $this->queueCount($count, $config);
        });
    }

    /** @param  callable(PosBridgeConfig): ?PosBridgeDispatch  $queue */
    private function safely(string $what, callable $queue): void
    {
        try {
            $config = $this->config();
            if (! $config->enabled || ! $this->accountingTenant()) {
                return;
            }
            $dispatch = $queue($config);
            if ($dispatch) {
                $this->send($dispatch);
            }
        } catch (Throwable $e) {
            Log::warning('Jembatan kasir: gagal memproses '.$what.': '.$e->getMessage());
        }
    }

    /** Buat baris kiriman untuk shift tertutup yang masuk rentang tanggal mulai. */
    public function queueShift(PosShift $shift, ?PosBridgeConfig $config = null): ?PosBridgeDispatch
    {
        if ($shift->status !== 'closed' || ! $shift->closed_at) {
            return null;
        }

        return $this->queue('shift', $shift->id, 'Shift #'.$shift->id.' - '.($shift->user_name ?: 'kasir'), $shift->closed_at, $config);
    }

    public function queuePurchase(PosPurchase $purchase, ?PosBridgeConfig $config = null): ?PosBridgeDispatch
    {
        $config ??= $this->config();
        if (! $config->post_cogs) {
            return null;
        }
        $supplier = $purchase->supplier_id ? $purchase->supplier()->value('name') : null;

        return $this->queue('purchase', $purchase->id, 'Stok masuk '.$purchase->number.($supplier ? ' - '.$supplier : ''), $purchase->received_at, $config);
    }

    public function queuePayment(PosSupplierPayment $payment, ?PosBridgeConfig $config = null): ?PosBridgeDispatch
    {
        $config ??= $this->config();
        if (! $config->post_cogs) {
            return null;
        }
        $supplier = $payment->supplier()->value('name');

        return $this->queue('payment', $payment->id, 'Bayar hutang '.$payment->number.($supplier ? ' - '.$supplier : ''), $payment->paid_at, $config);
    }

    public function queueCount(PosStockCount $count, ?PosBridgeConfig $config = null): ?PosBridgeDispatch
    {
        $config ??= $this->config();
        // Opname tanpa selisih nilai tidak perlu jurnal.
        if (! $config->post_cogs || abs((float) $count->difference_value) < 0.005) {
            return null;
        }

        return $this->queue('count', $count->id, 'Stok opname '.$count->number, $count->counted_at, $config);
    }

    /** Baris kiriman baru bila tanggalnya masuk rentang tanggal mulai; yang sudah ada dikembalikan apa adanya. */
    private function queue(string $kind, int $sourceId, string $label, \DateTimeInterface $moment, ?PosBridgeConfig $config): ?PosBridgeDispatch
    {
        $config ??= $this->config();
        $date = $this->clock->format($moment, 'Y-m-d');
        if ($config->start_date && $date < $config->start_date->toDateString()) {
            return null;
        }

        return PosBridgeDispatch::query()->firstOrCreate(
            ['kind' => $kind, 'source_id' => $sourceId],
            ['source_label' => $label, 'entry_date' => $date, 'status' => 'pending'],
        );
    }

    /**
     * Antrekan sumber yang belum punya kiriman (shift tertutup, stok masuk,
     * pembayaran hutang, dan stok opname), lalu kirim yang belum terkirim. Aman dipanggil berulang.
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

        $since = $config->start_date ? $this->clock->startOfDay($config->start_date->toDateString()) : null;
        $unqueued = fn (string $kind) => PosBridgeDispatch::query()->where('kind', $kind)->pluck('source_id')->all();

        $known = $unqueued('shift');
        PosShift::query()->where('status', 'closed')->whereNotNull('closed_at')
            ->when($known !== [], fn ($query) => $query->whereNotIn('id', $known))
            ->orderBy('closed_at')->limit(self::BATCH * 5)->get()
            ->each(function (PosShift $shift) use ($config, &$result) {
                if ($this->queueShift($shift, $config)) {
                    $result['queued']++;
                }
            });

        if ($config->post_cogs) {
            $known = $unqueued('purchase');
            PosPurchase::query()
                ->when($known !== [], fn ($query) => $query->whereNotIn('id', $known))
                ->when($since, fn ($query) => $query->where('received_at', '>=', $since))
                ->orderBy('received_at')->limit(self::BATCH * 5)->get()
                ->each(function (PosPurchase $purchase) use ($config, &$result) {
                    if ($this->queuePurchase($purchase, $config)) {
                        $result['queued']++;
                    }
                });

            $known = $unqueued('payment');
            PosSupplierPayment::query()
                ->when($known !== [], fn ($query) => $query->whereNotIn('id', $known))
                ->when($since, fn ($query) => $query->where('paid_at', '>=', $since))
                ->orderBy('paid_at')->limit(self::BATCH * 5)->get()
                ->each(function (PosSupplierPayment $payment) use ($config, &$result) {
                    if ($this->queuePayment($payment, $config)) {
                        $result['queued']++;
                    }
                });

            $known = $unqueued('count');
            PosStockCount::query()->where('difference_value', '!=', 0)
                ->when($known !== [], fn ($query) => $query->whereNotIn('id', $known))
                ->when($since, fn ($query) => $query->where('counted_at', '>=', $since))
                ->orderBy('counted_at')->limit(self::BATCH * 5)->get()
                ->each(function (PosStockCount $count) use ($config, &$result) {
                    if ($this->queueCount($count, $config)) {
                        $result['queued']++;
                    }
                });
        }

        $open = PosBridgeDispatch::query()->whereIn('status', ['pending', 'failed', 'held'])->orderBy('entry_date')->orderBy('id');
        foreach ((clone $open)->limit(self::BATCH)->get() as $dispatch) {
            $status = $this->send($dispatch)->status;
            $result[$status] = ($result[$status] ?? 0) + 1;
        }
        $result['remaining'] = (clone $open)->count();

        return $result;
    }

    /** Kirim satu sumber (shift, stok masuk, atau pembayaran hutang) sebagai jurnal. Hasilnya tersimpan di baris kiriman. */
    public function send(PosBridgeDispatch $dispatch): PosBridgeDispatch
    {
        if (in_array($dispatch->status, ['sent', 'skipped'], true)) {
            return $dispatch;
        }

        $dispatch->forceFill(['attempts' => $dispatch->attempts + 1])->save();

        try {
            $kind = self::KINDS[$dispatch->kind] ?? throw new \RuntimeException('Jenis kiriman tidak dikenal: '.$dispatch->kind);
            $company = $this->context->company();
            $this->connectAccounting();

            // Lapis kedua idempotensi: jurnal bersumber dokumen ini sudah ada di akuntansi.
            $existing = JournalEntry::withTrashed()->forSource($kind['type'], $dispatch->source_id)->forSourceModule(self::SOURCE_MODULE)->first();
            if ($existing) {
                return $this->mark($dispatch, 'sent', ['journal_id' => $existing->id, 'journal_number' => $existing->journal_number, 'error' => null, 'sent_at' => now()]);
            }

            $config = $this->config();
            $map = (array) $config->account_map;
            $built = match ($dispatch->kind) {
                'purchase' => $this->builder->forPurchase(PosPurchase::query()->findOrFail($dispatch->source_id), $map),
                'payment' => $this->builder->forSupplierPayment(PosSupplierPayment::query()->findOrFail($dispatch->source_id), $map),
                'count' => $this->builder->forStockCount(PosStockCount::query()->findOrFail($dispatch->source_id), $map),
                default => $this->builder->forShift(PosShift::query()->findOrFail($dispatch->source_id), $map, (bool) $config->post_cogs),
            };
            $dispatch->summary = $built['summary'];

            if ($built['empty']) {
                return $this->mark($dispatch, 'skipped', ['error' => 'Tidak ada nilai yang perlu dijurnal.', 'sent_at' => now()]);
            }

            $date = $dispatch->entry_date->toDateString();
            if ($reason = $this->heldReason($company, $date)) {
                return $this->mark($dispatch, 'held', ['error' => 'Ditahan: '.$reason]);
            }

            $journal = $this->journals->create([
                'source_type' => $kind['type'],
                'source_id' => $dispatch->source_id,
                'source_number' => $dispatch->source_label,
                'source_module' => self::SOURCE_MODULE,
                'journal_date' => $date,
                'description' => $kind['title'].' '.$date.' - '.$dispatch->source_label,
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
     * Hanya kunci periode/tahun fiskal yang dicek (`lockDenialForCompany`), bukan
     * seluruh `check()`: aturan tanggal mundur/masa depan di sana untuk input
     * manual dan memakai tanggal server (UTC), jadi shift yang ditutup 00.00-07.00
     * WIB (tanggal toko sudah "besok" menurut server) akan ditolak sebagai
     * transaksi masa depan.
     */
    private function heldReason(Company $company, string $date): ?string
    {
        $denial = $this->dateGuard->lockDenialForCompany($company, $date);
        if (! $denial) {
            return null;
        }

        $info = $denial->toArray();

        return match ($info['code']) {
            'PREVIOUS_FISCAL_YEAR_NOT_CLOSED' => 'tahun fiskal '.($info['meta']['blocking_fiscal_year'] ?? 'sebelumnya').' belum ditutup. Tutup tahun buku sebelumnya lalu kirim ulang.',
            default => 'periode akuntansi untuk tanggal '.$date.' sudah terkunci. Buka periodenya lalu kirim ulang.',
        };
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
