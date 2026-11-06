<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosCreditPayment;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSaleReturn;
use App\Modules\Pos\Models\PosShift;
use App\Shared\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosShiftService
{
    public function current(User $user): ?PosShift
    {
        return PosShift::query()
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();
    }

    public function requireOpen(User $user): PosShift
    {
        $shift = $this->current($user);
        if (! $shift) {
            throw ValidationException::withMessages([
                'shift' => ['Buka shift kasir terlebih dahulu.'],
            ]);
        }

        return $shift;
    }

    public function open(User $user, float $openingCash, ?string $notes = null): PosShift
    {
        return DB::connection('pos')->transaction(function () use ($user, $openingCash, $notes) {
            if ($this->current($user)) {
                throw ValidationException::withMessages([
                    'shift' => ['Shift Anda masih terbuka. Tutup dulu sebelum membuka shift baru.'],
                ]);
            }

            return PosShift::query()->create([
                'user_id' => $user->id,
                'user_name' => $user->name,
                'opened_at' => now(),
                'opening_cash' => round($openingCash, 2),
                'notes' => $notes,
                'status' => 'open',
            ]);
        });
    }

    public function close(PosShift $shift, float $countedCash, ?string $notes = null): PosShift
    {
        if ($shift->status !== 'open') {
            throw ValidationException::withMessages(['shift' => ['Shift sudah ditutup.']]);
        }

        $expected = $this->summary($shift)['expected_cash'];

        $shift->forceFill([
            'closed_at' => now(),
            'expected_cash' => $expected,
            'counted_cash' => round($countedCash, 2),
            'cash_difference' => round($countedCash - $expected, 2),
            'notes' => trim(implode("\n", array_filter([$shift->notes, $notes]))) ?: null,
            'status' => 'closed',
        ])->save();

        return $shift->refresh();
    }

    /**
     * Rekap satu shift. Uang tunai di laci = modal awal + tunai yang benar-benar
     * diterima dari penjualan (dibayar - kembalian) + pelunasan kasbon tunai
     * - uang retur tunai.
     *
     * @return array<string,mixed>
     */
    public function summary(PosShift $shift): array
    {
        $sales = PosSale::query()->where('shift_id', $shift->id)->where('status', 'completed');

        $byMethod = (clone $sales)
            ->selectRaw('payment_method, COUNT(*) as count, SUM(total) as total, SUM(paid_amount - change_amount) as received, SUM(credit_amount) as credit')
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($row) => [
                'payment_method' => $row->payment_method,
                'count' => (int) $row->count,
                'total' => round((float) $row->total, 2),
                'received' => round((float) $row->received, 2),
                'credit' => round((float) $row->credit, 2),
            ])
            ->values()
            ->all();

        // Penjualan kasbon bisa dibayar sebagian tunai (uang muka).
        $cashFromSales = (float) (clone $sales)->whereIn('payment_method', ['cash', 'credit'])->sum(DB::raw('paid_amount - change_amount'));
        $creditPayments = PosCreditPayment::query()->where('shift_id', $shift->id);
        $cashFromCredit = (float) (clone $creditPayments)->where('method', 'cash')->sum('amount');
        // Retur yang diproses di shift ini; yang tunai keluar dari laci.
        $returns = PosSaleReturn::query()->where('shift_id', $shift->id);
        $cashRefunds = (float) (clone $returns)->where('refund_method', 'cash')->sum('total');

        return [
            'shift_id' => $shift->id,
            'opening_cash' => round((float) $shift->opening_cash, 2),
            'sales_count' => (int) (clone $sales)->count(),
            'sales_total' => round((float) (clone $sales)->sum('total'), 2),
            'voided_count' => PosSale::query()->where('shift_id', $shift->id)->where('status', 'voided')->count(),
            'by_method' => $byMethod,
            'cash_from_sales' => round($cashFromSales, 2),
            'credit_payments_total' => round((float) (clone $creditPayments)->sum('amount'), 2),
            'cash_from_credit_payments' => round($cashFromCredit, 2),
            'returns_count' => (int) (clone $returns)->count(),
            'returns_total' => round((float) (clone $returns)->sum('total'), 2),
            'cash_refunds' => round($cashRefunds, 2),
            'expected_cash' => round((float) $shift->opening_cash + $cashFromSales + $cashFromCredit - $cashRefunds, 2),
        ];
    }
}
