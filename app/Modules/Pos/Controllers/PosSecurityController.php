<?php

namespace App\Modules\Pos\Controllers;

use App\Modules\Pos\Services\PosSecurityService;
use App\Modules\Pos\Services\PosSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** PIN kepala toko (Fase 4b). PIN-nya sendiri tidak pernah dikembalikan. */
class PosSecurityController extends PosController
{
    public function __construct(
        private readonly PosSecurityService $security,
        private readonly PosSettingService $settings,
    ) {}

    public function setPin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pin' => ['required', 'string', 'regex:/^\d{4,8}$/'],
            'current_pin' => ['nullable', 'string', 'max:12'],
        ], ['pin.regex' => 'PIN harus 4 sampai 8 angka.']);

        $this->security->setPin($this->user($request), $data['pin'], $data['current_pin'] ?? null);

        return $this->successResponse(['supervisor_pin_set' => true], 'PIN kepala toko disimpan.');
    }

    public function clearPin(Request $request): JsonResponse
    {
        $this->security->clearPin($this->user($request));
        // Tanpa PIN, kasir tidak mungkin lagi membatalkan lewat PIN.
        $this->settings->update(['cashier_void_with_pin' => '0']);

        return $this->successResponse(['supervisor_pin_set' => false], 'PIN kepala toko dihapus.');
    }
}
