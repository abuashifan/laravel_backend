<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosCustomer;
use App\Modules\Pos\Models\PosProduct;
use App\Modules\Pos\Models\PosSale;
use App\Shared\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosSaleService
{
    public const METHODS = ['cash', 'transfer', 'qris', 'credit'];

    public const RECEIPT_MODES = ['none', 'digital', 'print'];

    public function __construct(
        private readonly PosShiftService $shifts,
        private readonly PosStockService $stock,
        private readonly PosNumberService $numbers,
    ) {}

    /**
     * Simpan satu transaksi kasir.
     *
     * Aturan bayar:
     * - cash: dibayar >= total, kembalian = dibayar - total.
     * - transfer / qris: dibayar = total, tanpa kembalian.
     * - credit (kasbon): wajib pelanggan; dibayar (uang muka tunai) 0..total,
     *   sisanya menambah kasbon pelanggan.
     *
     * @param  array<string,mixed>  $data
     */
    public function create(User $user, array $data): PosSale
    {
        $shift = $this->shifts->requireOpen($user);

        return DB::connection('tenant')->transaction(function () use ($user, $data, $shift) {
            $lines = [];
            $subtotal = 0.0;

            foreach ((array) $data['lines'] as $index => $input) {
                /** @var PosProduct|null $product */
                $product = PosProduct::query()->find((int) $input['product_id']);
                if (! $product || ! $product->is_active) {
                    throw ValidationException::withMessages(["lines.{$index}.product_id" => ['Produk tidak ditemukan atau nonaktif.']]);
                }

                $quantity = round((float) $input['quantity'], 3);
                $unitPrice = round((float) ($input['unit_price'] ?? $product->price), 2);
                $discount = round((float) ($input['discount_amount'] ?? 0), 2);
                $lineTotal = round($quantity * $unitPrice - $discount, 2);
                if ($lineTotal < 0) {
                    throw ValidationException::withMessages(["lines.{$index}.discount_amount" => ['Diskon melebihi harga baris.']]);
                }

                $subtotal += $lineTotal;
                $lines[] = compact('product', 'quantity', 'unitPrice', 'discount', 'lineTotal');
            }

            $discountAmount = round((float) ($data['discount_amount'] ?? 0), 2);
            $total = round($subtotal - $discountAmount, 2);
            if ($total < 0) {
                throw ValidationException::withMessages(['discount_amount' => ['Diskon melebihi total belanja.']]);
            }

            $method = (string) $data['payment_method'];
            $paid = round((float) ($data['paid_amount'] ?? 0), 2);
            $customer = null;
            if (! empty($data['customer_id'])) {
                $customer = PosCustomer::query()->lockForUpdate()->find((int) $data['customer_id']);
                if (! $customer) {
                    throw ValidationException::withMessages(['customer_id' => ['Pelanggan tidak ditemukan.']]);
                }
            }

            [$paid, $change, $credit] = match ($method) {
                'cash' => $paid + 0.0001 >= $total
                    ? [$paid, round($paid - $total, 2), 0.0]
                    : throw ValidationException::withMessages(['paid_amount' => ['Uang yang dibayar kurang dari total.']]),
                'transfer', 'qris' => [$total, 0.0, 0.0],
                'credit' => $this->creditSplit($customer, $paid, $total),
            };

            $sale = PosSale::query()->create([
                'number' => $this->numbers->next(PosSale::class, 'PJ'),
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'customer_id' => $customer?->id,
                'sold_at' => now(),
                'subtotal' => round($subtotal, 2),
                'discount_amount' => $discountAmount,
                'total' => $total,
                'payment_method' => $method,
                'paid_amount' => $paid,
                'change_amount' => $change,
                'credit_amount' => $credit,
                'receipt_mode' => (string) ($data['receipt_mode'] ?? 'none'),
                'status' => 'completed',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                /** @var PosProduct $product */
                $product = $line['product'];
                $sale->lines()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'unit' => $product->unit,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unitPrice'],
                    'discount_amount' => $line['discount'],
                    'line_total' => $line['lineTotal'],
                    'unit_cost' => (float) $product->cost,
                ]);

                $this->stock->move($product, -$line['quantity'], 'sale', 'pos_sale', $sale->id, $sale->number, $user->id, enforceAvailability: true);
            }

            if ($customer && $credit > 0) {
                $customer->forceFill(['credit_balance' => round((float) $customer->credit_balance + $credit, 2)])->save();
            }

            return $sale->load('lines', 'customer');
        });
    }

    /**
     * Batalkan transaksi. Hanya selama shift-nya masih terbuka, supaya setoran
     * shift yang sudah ditutup tidak berubah diam-diam.
     */
    public function void(PosSale $sale, User $user, string $reason): PosSale
    {
        if ($sale->status !== 'completed') {
            throw ValidationException::withMessages(['sale' => ['Transaksi sudah dibatalkan.']]);
        }

        return DB::connection('tenant')->transaction(function () use ($sale, $user, $reason) {
            $shift = $sale->shift()->lockForUpdate()->first();
            if (! $shift || $shift->status !== 'open') {
                throw ValidationException::withMessages(['sale' => ['Shift transaksi ini sudah ditutup; transaksi tidak bisa dibatalkan.']]);
            }

            if ($sale->credit_amount > 0 && $sale->customer_id) {
                $customer = PosCustomer::query()->lockForUpdate()->findOrFail($sale->customer_id);
                if ((float) $customer->credit_balance + 0.0001 < $sale->credit_amount) {
                    throw ValidationException::withMessages(['sale' => ['Kasbon dari transaksi ini sudah dibayar sebagian; transaksi tidak bisa dibatalkan.']]);
                }
                $customer->forceFill(['credit_balance' => round((float) $customer->credit_balance - $sale->credit_amount, 2)])->save();
            }

            foreach ($sale->lines as $line) {
                $product = $line->product_id ? PosProduct::query()->find($line->product_id) : null;
                if ($product) {
                    $this->stock->move($product, (float) $line->quantity, 'void', 'pos_sale', $sale->id, 'Batal '.$sale->number, $user->id);
                }
            }

            $sale->forceFill([
                'status' => 'voided',
                'voided_at' => now(),
                'voided_by' => $user->id,
                'void_reason' => $reason,
            ])->save();

            return $sale->refresh()->load('lines', 'customer');
        });
    }

    /** @return array{0: float, 1: float, 2: float} [dibayar, kembalian, kasbon] */
    private function creditSplit(?PosCustomer $customer, float $paid, float $total): array
    {
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => ['Penjualan kasbon wajib memilih pelanggan.']]);
        }
        if ($paid > $total) {
            throw ValidationException::withMessages(['paid_amount' => ['Uang muka kasbon tidak boleh melebihi total.']]);
        }

        return [$paid, 0.0, round($total - $paid, 2)];
    }
}
