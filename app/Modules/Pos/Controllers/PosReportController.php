<?php

namespace App\Modules\Pos\Controllers;

use App\Modules\Pos\Services\PosClock;
use App\Modules\Pos\Services\PosPricingService;
use App\Modules\Pos\Services\PosReportService;
use App\Modules\Pos\Services\PosSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosReportController extends PosController
{
    public function summary(Request $request, PosReportService $reports, PosClock $clock): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $today = $clock->today();

        return $this->successResponse($reports->summary($data['date_from'] ?? $today, $data['date_to'] ?? $today));
    }

    /** Pengaturan toko dibaca semua pengguna POS (dipakai untuk mencetak struk). */
    public function settings(PosSettingService $settings): JsonResponse
    {
        return $this->successResponse($settings->all());
    }

    public function updateSettings(Request $request, PosSettingService $settings): JsonResponse
    {
        $data = $request->validate([
            'store_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'store_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'store_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'receipt_footer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'default_receipt_mode' => ['sometimes', Rule::in(['none', 'digital', 'print'])],
            'allow_negative_stock' => ['sometimes', Rule::in(['0', '1', 0, 1, true, false])],
            'paper_width' => ['sometimes', Rule::in(['58', '80', 58, 80])],
            'tax_mode' => ['sometimes', Rule::in(PosPricingService::TAX_MODES)],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'tax_name' => ['sometimes', 'nullable', 'string', 'max:20'],
            'rounding_unit' => ['sometimes', Rule::in([...PosPricingService::ROUNDING_UNITS, 0, 100, 500, 1000])],
            'rounding_mode' => ['sometimes', Rule::in(PosPricingService::ROUNDING_MODES)],
            'timezone' => ['sometimes', Rule::in(PosClock::TIMEZONES)],
        ]);

        if (array_key_exists('allow_negative_stock', $data)) {
            $data['allow_negative_stock'] = filter_var($data['allow_negative_stock'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        return $this->successResponse($settings->update($data), 'Pengaturan toko disimpan.');
    }
}
