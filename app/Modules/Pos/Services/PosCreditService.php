<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosCreditPayment;
use App\Modules\Pos\Models\PosCustomer;
use App\Shared\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Pelunasan kasbon pelanggan. */
class PosCreditService
{
    public function __construct(
        private readonly PosShiftService $shifts,
        private readonly PosNumberService $numbers,
    ) {}

    public function pay(User $user, PosCustomer $customer, float $amount, string $method, ?string $notes = null): PosCreditPayment
    {
        // Pelunasan tunai masuk laci, jadi harus tercatat di shift yang terbuka.
        $shift = $method === 'cash' ? $this->shifts->requireOpen($user) : $this->shifts->current($user);

        return DB::connection('pos')->transaction(function () use ($user, $customer, $amount, $method, $notes, $shift) {
            $locked = PosCustomer::query()->lockForUpdate()->findOrFail($customer->id);
            $amount = round($amount, 2);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => ['Jumlah bayar harus lebih dari 0.']]);
            }
            if ($amount > (float) $locked->credit_balance + 0.0001) {
                throw ValidationException::withMessages(['amount' => ['Jumlah bayar melebihi sisa kasbon.']]);
            }

            $payment = PosCreditPayment::query()->create([
                'number' => $this->numbers->next(PosCreditPayment::class, 'KB'),
                'customer_id' => $locked->id,
                'shift_id' => $shift?->id,
                'amount' => $amount,
                'method' => $method,
                'paid_at' => now(),
                'user_id' => $user->id,
                'user_name' => $user->name,
                'notes' => $notes,
            ]);

            $locked->forceFill(['credit_balance' => round((float) $locked->credit_balance - $amount, 2)])->save();

            return $payment->load('customer');
        });
    }
}
