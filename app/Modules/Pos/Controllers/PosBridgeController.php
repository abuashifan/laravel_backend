<?php

namespace App\Modules\Pos\Controllers;

use App\Modules\Pos\Models\PosBridgeDispatch;
use App\Modules\Pos\Services\PosAuditService;
use App\Modules\Pos\Services\PosBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Hubungkan ke Akuntansi (Fase 5): pengaturan jembatan, pemetaan akun, riwayat kiriman. */
class PosBridgeController extends PosController
{
    public function __construct(
        private readonly PosBridgeService $bridge,
        private readonly PosAuditService $audit,
    ) {}

    public function show(): JsonResponse
    {
        return $this->successResponse($this->bridge->state());
    }

    /** Akun akuntansi yang bisa dipilih beserta usulan pemetaannya. */
    public function accounts(): JsonResponse
    {
        abort_if($this->bridge->accountingTenant() === null, 422, 'Toko ini belum memakai Finlite Accounting.');

        return $this->successResponse($this->bridge->accountChoices());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'post_cogs' => ['sometimes', 'boolean'],
            'account_map' => ['sometimes', 'array'],
            'account_map.*' => ['nullable', 'integer'],
        ]);

        $before = $this->bridge->config();
        $wasEnabled = (bool) $before->enabled;
        $config = $this->bridge->save($this->user($request), $data);

        $summary = match (true) {
            $config->enabled && ! $wasEnabled => 'Jembatan ke Finlite Accounting disambungkan (mulai '.$config->start_date?->toDateString().').',
            ! $config->enabled && $wasEnabled => 'Jembatan ke Finlite Accounting diputus.',
            default => 'Pengaturan jembatan ke Finlite Accounting diubah.',
        };
        $this->audit->record('settings.update', $summary, $this->user($request), ['type' => 'bridge'], [
            'enabled' => [$wasEnabled, (bool) $config->enabled],
            'start_date' => [$before->start_date?->toDateString(), $config->start_date?->toDateString()],
            'post_cogs' => [(bool) $before->post_cogs, (bool) $config->post_cogs],
        ]);

        // Baru disambungkan: shift lama yang masuk rentang tanggal mulai ikut dikirim.
        $sync = $config->enabled ? $this->bridge->sync() : null;

        return $this->successResponse(['state' => $this->bridge->state(), 'sync' => $sync], 'Pengaturan jembatan disimpan.');
    }

    public function dispatches(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(PosBridgeDispatch::STATUSES)]]);
        $query = PosBridgeDispatch::query()->latest('entry_date')->latest('id');
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function sync(): JsonResponse
    {
        return $this->successResponse($this->bridge->sync(), 'Sinkronisasi selesai.');
    }

    public function retry(int $id): JsonResponse
    {
        $dispatch = $this->bridge->retry(PosBridgeDispatch::query()->findOrFail($id));

        return $this->successResponse($dispatch, $dispatch->status === 'sent' ? 'Terkirim ke Finlite Accounting.' : 'Belum terkirim: '.$dispatch->error);
    }
}
