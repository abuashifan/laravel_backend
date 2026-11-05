<?php

namespace App\Modules\Pos\Controllers;

use App\Modules\Pos\Models\PosCreditPayment;
use App\Modules\Pos\Models\PosCustomer;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Services\PosCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosCustomerController extends PosController
{
    public function index(Request $request): JsonResponse
    {
        $query = PosCustomer::query()->orderBy('name');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereRaw(...$this->containsInsensitive('name', $search))
                    ->orWhere('phone', 'like', '%'.$search.'%');
            });
        }
        if (filter_var($request->query('with_credit', false), FILTER_VALIDATE_BOOLEAN)) {
            $query->where('credit_balance', '>', 0);
        }
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse(PosCustomer::query()->findOrFail($id));
    }

    /** Dipakai juga untuk "tambah pelanggan cepat" dari layar kasir: cukup nama. */
    public function store(Request $request): JsonResponse
    {
        $customer = PosCustomer::query()->create($request->validate($this->rules()));

        return $this->successResponse($customer, 'Pelanggan dibuat.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $customer = PosCustomer::query()->findOrFail($id);
        $customer->update($request->validate($this->rules(partial: true)));

        return $this->successResponse($customer->refresh(), 'Pelanggan diperbarui.');
    }

    public function destroy(int $id): JsonResponse
    {
        $customer = PosCustomer::query()->findOrFail($id);

        if ((float) $customer->credit_balance > 0) {
            return $this->validationErrorResponse(['customer' => ['Pelanggan masih punya kasbon; lunasi dulu.']]);
        }

        if (PosSale::query()->where('customer_id', $id)->exists()) {
            $customer->update(['is_active' => false]);

            return $this->successResponse($customer, 'Pelanggan punya riwayat transaksi, jadi dinonaktifkan.');
        }

        $customer->delete();

        return $this->successResponse(null, 'Pelanggan dihapus.');
    }

    /** Riwayat kasbon: penjualan kasbon dan pelunasannya, terbaru di atas. */
    public function credit(int $id): JsonResponse
    {
        $customer = PosCustomer::query()->findOrFail($id);

        $sales = PosSale::query()
            ->where('customer_id', $id)
            ->where('status', 'completed')
            ->where('credit_amount', '>', 0)
            ->latest('sold_at')
            ->limit(200)
            ->get(['id', 'number', 'sold_at', 'total', 'paid_amount', 'credit_amount']);

        $payments = PosCreditPayment::query()
            ->where('customer_id', $id)
            ->latest('paid_at')
            ->limit(200)
            ->get(['id', 'number', 'paid_at', 'amount', 'method', 'user_name', 'notes']);

        return $this->successResponse([
            'customer' => $customer,
            'sales' => $sales,
            'payments' => $payments,
        ]);
    }

    public function pay(Request $request, int $id, PosCreditService $credit): JsonResponse
    {
        $customer = PosCustomer::query()->findOrFail($id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['cash', 'transfer', 'qris'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $payment = $credit->pay($this->user($request), $customer, (float) $data['amount'], $data['method'], $data['notes'] ?? null);

        return $this->successResponse($payment, 'Pembayaran kasbon dicatat.', 201);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
