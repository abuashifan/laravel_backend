<?php

namespace App\Modules\Pos\Controllers;

use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Services\PosAuditService;
use App\Modules\Pos\Services\PosClock;
use App\Modules\Pos\Services\PosPricingService;
use App\Modules\Pos\Services\PosReportService;
use App\Modules\Pos\Services\PosReportTableService;
use App\Modules\Pos\Services\PosSecurityService;
use App\Modules\Pos\Services\PosSettingService;
use App\Shared\Export\ExcelExportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * Dasbor pemilik: hari ini dibanding kemarin, tren 7 hari, produk terlaris,
     * stok menipis, shift yang masih buka.
     */
    public function dashboard(PosReportService $reports, PosReportTableService $tables, PosClock $clock): JsonResponse
    {
        $today = $clock->today();
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();
        $week = $reports->summary(CarbonImmutable::parse($today)->subDays(6)->toDateString(), $today);
        $pick = fn (array $summary) => array_intersect_key($summary, array_flip(['sales_count', 'sales_total', 'net_sales', 'gross_profit', 'returns_total', 'voided_count']));
        $todaySummary = $reports->summary($today, $today);

        return $this->successResponse([
            'date' => $today,
            'today' => $pick($todaySummary),
            'yesterday' => $pick($reports->summary($yesterday, $yesterday)),
            'week_by_day' => $week['by_day'],
            'week_net_sales' => $week['net_sales'],
            'week_gross_profit' => $week['gross_profit'],
            'top_products' => array_slice($todaySummary['top_products'], 0, 5),
            'low_stock_count' => count($tables->table('low-stock', $today, $today)['rows']),
            'outstanding_credit' => $todaySummary['outstanding_credit'],
            'open_shifts' => PosShift::query()->where('status', 'open')->orderBy('opened_at')->get(['id', 'user_name', 'opened_at']),
        ]);
    }

    /** Laporan rinci (`PosReportTableService::TYPES`) untuk rentang tanggal toko. */
    public function table(Request $request, string $type, PosReportTableService $tables, PosClock $clock): JsonResponse
    {
        [$from, $to] = $this->range($request, $clock);

        return $this->successResponse($tables->table($type, $from, $to));
    }

    public function exportTable(Request $request, string $type, PosReportTableService $tables, PosClock $clock, ExcelExportService $excel): StreamedResponse
    {
        [$from, $to] = $this->range($request, $clock);
        $table = $tables->table($type, $from, $to);

        return $excel->downloadFromArray(
            PosReportTableService::exportRows($table),
            array_column($table['columns'], 'label'),
            'laporan-'.$type.'-'.$from.'-'.$to,
        );
    }

    /** @return array{0: string, 1: string} */
    private function range(Request $request, PosClock $clock): array
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $today = $clock->today();

        return [$data['date_from'] ?? $today, $data['date_to'] ?? $today];
    }

    /** Pengaturan toko dibaca semua pengguna POS (dipakai untuk mencetak struk). */
    public function settings(PosSettingService $settings, PosSecurityService $security): JsonResponse
    {
        return $this->successResponse($this->withSecurity($settings->all(), $security));
    }

    public function updateSettings(Request $request, PosSettingService $settings, PosSecurityService $security, PosAuditService $audit): JsonResponse
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
            'max_discount_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'cashier_void_with_pin' => ['sometimes', Rule::in(['0', '1', 0, 1, true, false])],
        ]);

        if (array_key_exists('allow_negative_stock', $data)) {
            $data['allow_negative_stock'] = filter_var($data['allow_negative_stock'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        if (array_key_exists('cashier_void_with_pin', $data)) {
            $data['cashier_void_with_pin'] = filter_var($data['cashier_void_with_pin'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }
        if (array_key_exists('max_discount_percent', $data)) {
            $data['max_discount_percent'] = $data['max_discount_percent'] === null ? '' : (string) (float) $data['max_discount_percent'];
        }
        if (($data['cashier_void_with_pin'] ?? '0') === '1' && ! $security->pinIsSet()) {
            return $this->validationErrorResponse(['cashier_void_with_pin' => ['Buat PIN kepala toko dulu sebelum mengizinkan kasir membatalkan dengan PIN.']]);
        }

        $before = $settings->all();
        $after = $settings->update($data);

        // Hanya kunci yang benar-benar berubah masuk log (nilai lama -> baru).
        $changes = [];
        foreach (array_intersect_key($data, $after) as $key => $_) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changes[$key] = [$before[$key] ?? null, $after[$key] ?? null];
            }
        }
        if ($changes !== []) {
            $audit->record('settings.update', 'Pengaturan toko diubah: '.implode(', ', array_keys($changes)).'.', $this->user($request), changes: $changes);
        }

        return $this->successResponse($this->withSecurity($after, $security), 'Pengaturan toko disimpan.');
    }

    /**
     * PIN-nya sendiri tidak pernah keluar; layar cuma perlu tahu sudah dibuat atau belum.
     *
     * @param  array<string,string>  $values
     * @return array<string,string>
     */
    private function withSecurity(array $values, PosSecurityService $security): array
    {
        return $values + ['supervisor_pin_set' => $security->pinIsSet() ? '1' : '0'];
    }
}
