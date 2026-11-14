<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosProduct;
use App\Modules\Pos\Models\PosPurchase;
use App\Modules\Pos\Models\PosSupplier;
use App\Modules\Pos\Models\PosSupplierPayment;
use App\Shared\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stok masuk (pembelian sederhana) dan hutang ke pemasok.
 *
 * Uang pembelian dianggap keluar dari kas pemilik, bukan dari laci kasir,
 * jadi tidak memengaruhi setoran shift.
 */
class PosPurchaseService
{
    public const METHODS = ['cash', 'transfer', 'qris'];

    public function __construct(
        private readonly PosStockService $stock,
        private readonly PosNumberService $numbers,
        private readonly PosAuditService $audit,
    ) {}

    /**
     * Harga modal produk diperbarui dengan rata-rata tertimbang:
     * (stok lama x modal lama + jumlah masuk x harga beli) / (stok lama + jumlah masuk).
     * Stok minus dihitung nol supaya rata-rata tidak terbalik. Produk tanpa
     * lacak stok langsung memakai harga beli terakhir.
     *
     * @param  array<string,mixed>  $data
     */
    public function create(User $user, array $data): PosPurchase
    {
        return DB::connection('pos')->transaction(function () use ($user, $data) {
            $supplier = null;
            if (! empty($data['supplier_id'])) {
                $supplier = PosSupplier::query()->lockForUpdate()->find((int) $data['supplier_id']);
                if (! $supplier) {
                    throw ValidationException::withMessages(['supplier_id' => ['Pemasok tidak ditemukan.']]);
                }
            }

            $lines = [];
            $total = 0.0;
            foreach ((array) $data['lines'] as $index => $input) {
                $product = PosProduct::query()->find((int) $input['product_id']);
                if (! $product) {
                    throw ValidationException::withMessages(["lines.{$index}.product_id" => ['Produk tidak ditemukan.']]);
                }
                $quantity = round((float) $input['quantity'], 3);
                $unitCost = round((float) $input['unit_cost'], 2);
                $lineTotal = round($quantity * $unitCost, 2);
                $total += $lineTotal;
                $lines[] = compact('product', 'quantity', 'unitCost', 'lineTotal');
            }
            $total = round($total, 2);

            $paid = array_key_exists('paid_amount', $data) && $data['paid_amount'] !== null
                ? round((float) $data['paid_amount'], 2)
                : $total;
            if ($paid > $total + 0.0001) {
                throw ValidationException::withMessages(['paid_amount' => ['Jumlah dibayar melebihi total pembelian.']]);
            }
            $payable = round($total - $paid, 2);
            if ($payable > 0 && ! $supplier) {
                throw ValidationException::withMessages(['supplier_id' => ['Pembelian yang belum lunas wajib memilih pemasok.']]);
            }

            $purchase = PosPurchase::query()->create([
                'number' => $this->numbers->next(PosPurchase::class, 'SM'),
                'supplier_id' => $supplier?->id,
                'received_at' => now(),
                'user_id' => $user->id,
                'user_name' => $user->name,
                'total' => $total,
                'paid_amount' => $paid,
                'payable_amount' => $payable,
                'payment_method' => $paid > 0 ? (string) ($data['payment_method'] ?? 'cash') : null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $costChanges = [];
            foreach ($lines as $line) {
                /** @var PosProduct $product */
                $product = PosProduct::query()->lockForUpdate()->findOrFail($line['product']->id);
                $purchase->lines()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit' => $product->unit,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unitCost'],
                    'line_total' => $line['lineTotal'],
                ]);

                $oldStock = max(0.0, (float) $product->stock);
                $oldCost = (float) $product->cost;
                $newCost = $product->track_stock && $oldStock + $line['quantity'] > 0
                    ? ($oldStock * (float) $product->cost + $line['quantity'] * $line['unitCost']) / ($oldStock + $line['quantity'])
                    : $line['unitCost'];
                $product->forceFill(['cost' => round($newCost, 2)])->save();
                if (abs(round($newCost, 2) - $oldCost) > 0.004) {
                    $costChanges[$product->name] = [$oldCost, round($newCost, 2)];
                }

                $this->stock->move($product, $line['quantity'], 'purchase', 'pos_purchase', $purchase->id, $purchase->number, $user->id);
            }

            if ($supplier && $payable > 0) {
                $supplier->forceFill(['payable_balance' => round((float) $supplier->payable_balance + $payable, 2)])->save();
            }

            $this->audit->record(
                'stock.in',
                sprintf('Stok masuk %s: %d jenis barang, total Rp %s%s.', $purchase->number, count($lines), number_format($total, 0, ',', '.'), $supplier ? ' dari '.$supplier->name : ''),
                $user,
                ['type' => 'purchase', 'id' => $purchase->id, 'label' => $purchase->number],
                array_filter(['total' => $total, 'dibayar' => $paid, 'hutang' => $payable, 'harga_modal' => $costChanges ?: null]),
            );

            return $purchase->load('lines', 'supplier');
        });
    }

    public function pay(PosSupplier $supplier, User $user, float $amount, string $method, ?string $notes = null): PosSupplierPayment
    {
        return DB::connection('pos')->transaction(function () use ($supplier, $user, $amount, $method, $notes) {
            /** @var PosSupplier $locked */
            $locked = PosSupplier::query()->lockForUpdate()->findOrFail($supplier->id);
            $amount = round($amount, 2);
            if ($amount > (float) $locked->payable_balance + 0.0001) {
                throw ValidationException::withMessages(['amount' => ['Pembayaran melebihi sisa hutang ke pemasok.']]);
            }

            $payment = PosSupplierPayment::query()->create([
                'number' => $this->numbers->next(PosSupplierPayment::class, 'BH'),
                'supplier_id' => $locked->id,
                'amount' => $amount,
                'method' => $method,
                'paid_at' => now(),
                'user_id' => $user->id,
                'user_name' => $user->name,
                'notes' => $notes,
            ]);

            $locked->forceFill(['payable_balance' => round((float) $locked->payable_balance - $amount, 2)])->save();
            $supplier->setRawAttributes($locked->getAttributes(), true);

            $this->audit->record(
                'supplier.pay',
                sprintf('Bayar hutang %s Rp %s (%s).', $locked->name, number_format($amount, 0, ',', '.'), $method),
                $user,
                ['type' => 'supplier', 'id' => $locked->id, 'label' => $locked->name],
                ['hutang' => [round((float) $locked->payable_balance + $amount, 2), (float) $locked->payable_balance], 'nomor' => $payment->number],
            );

            return $payment;
        });
    }
}
