<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosCustomer;
use App\Modules\Pos\Models\PosProduct;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSaleLine;
use App\Modules\Pos\Models\PosSaleReturn;
use App\Modules\Pos\Models\PosSaleReturnLine;
use App\Shared\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retur penjualan, sebagian atau penuh.
 *
 * Berbeda dengan pembatalan (hanya selama shift transaksi masih terbuka),
 * retur boleh kapan saja dan dicatat di shift kasir yang memprosesnya:
 * uang tunai yang dikembalikan mengurangi isi laci shift itu.
 */
class PosReturnService
{
    public const REFUND_METHODS = ['cash', 'transfer', 'qris', 'credit'];

    public function __construct(
        private readonly PosShiftService $shifts,
        private readonly PosStockService $stock,
        private readonly PosNumberService $numbers,
    ) {}

    /**
     * Jumlah yang masih bisa diretur per baris penjualan.
     *
     * @return array<int, float> sale_line_id => jumlah
     */
    public function returnable(PosSale $sale): array
    {
        $returned = PosSaleReturnLine::query()
            ->whereIn('sale_line_id', $sale->lines->pluck('id'))
            ->selectRaw('sale_line_id, SUM(quantity) as quantity')
            ->groupBy('sale_line_id')
            ->pluck('quantity', 'sale_line_id');

        return $sale->lines
            ->mapWithKeys(fn (PosSaleLine $line) => [$line->id => round((float) $line->quantity - (float) ($returned[$line->id] ?? 0), 3)])
            ->all();
    }

    /** @param  array<string,mixed>  $data */
    public function create(PosSale $sale, User $user, array $data): PosSaleReturn
    {
        if ($sale->status !== 'completed') {
            throw ValidationException::withMessages(['sale' => ['Transaksi yang sudah dibatalkan tidak bisa diretur.']]);
        }
        $shift = $this->shifts->requireOpen($user);

        return DB::connection('pos')->transaction(function () use ($sale, $user, $data, $shift) {
            /** @var PosSale $sale */
            $sale = PosSale::query()->lockForUpdate()->with('lines')->findOrFail($sale->id);
            $returnable = $this->returnable($sale);
            $lines = $sale->lines->keyBy('id');
            // Diskon total transaksi ikut dibagi rata ke setiap baris.
            $factor = $sale->subtotal > 0 ? $sale->total / $sale->subtotal : 0.0;

            $items = [];
            $total = 0.0;
            foreach ((array) $data['lines'] as $index => $input) {
                $line = $lines->get((int) $input['sale_line_id']);
                if (! $line) {
                    throw ValidationException::withMessages(["lines.{$index}.sale_line_id" => ['Baris bukan bagian dari transaksi ini.']]);
                }
                $quantity = round((float) $input['quantity'], 3);
                if ($quantity > ($returnable[$line->id] ?? 0) + 0.0001) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => ["Jumlah retur {$line->product_name} melebihi sisa yang bisa diretur."]]);
                }
                $amount = $line->quantity > 0 ? round($line->line_total * $quantity / $line->quantity * $factor, 2) : 0.0;
                $total += $amount;
                $items[] = ['line' => $line, 'quantity' => $quantity, 'amount' => $amount, 'restock' => (bool) ($input['restock'] ?? true)];
            }

            // Pembulatan per baris tidak boleh membuat uang kembali melebihi nilai transaksi.
            $alreadyReturned = (float) PosSaleReturn::query()->where('sale_id', $sale->id)->sum('total');
            $total = min(round($total, 2), round($sale->total - $alreadyReturned, 2));

            $method = (string) ($data['refund_method'] ?? ($sale->payment_method === 'credit' ? 'credit' : $sale->payment_method));
            if ($method === 'credit') {
                $this->reduceCredit($sale, $total);
            }

            $return = PosSaleReturn::query()->create([
                'number' => $this->numbers->next(PosSaleReturn::class, 'RT'),
                'sale_id' => $sale->id,
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'customer_id' => $sale->customer_id,
                'returned_at' => now(),
                'total' => $total,
                'refund_method' => $method,
                'reason' => $data['reason'] ?? null,
            ]);

            foreach ($items as $item) {
                /** @var PosSaleLine $line */
                $line = $item['line'];
                $return->lines()->create([
                    'sale_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_name' => $line->product_name,
                    'quantity' => $item['quantity'],
                    'amount' => $item['amount'],
                    'unit_cost' => $line->unit_cost,
                    'restock' => $item['restock'],
                ]);

                $product = $line->product_id ? PosProduct::query()->find($line->product_id) : null;
                if ($product && $item['restock']) {
                    $this->stock->move($product, $item['quantity'], 'return', 'pos_sale_return', $return->id, $return->number, $user->id);
                }
            }

            return $return->load('lines');
        });
    }

    /** Retur kasbon memotong sisa kasbon pelanggan, tidak boleh membuatnya minus. */
    private function reduceCredit(PosSale $sale, float $amount): void
    {
        if (! $sale->customer_id) {
            throw ValidationException::withMessages(['refund_method' => ['Potong kasbon hanya untuk transaksi dengan pelanggan.']]);
        }
        $customer = PosCustomer::query()->lockForUpdate()->findOrFail($sale->customer_id);
        if ($amount > (float) $customer->credit_balance + 0.0001) {
            throw ValidationException::withMessages(['refund_method' => [
                'Sisa kasbon pelanggan tinggal Rp '.number_format((float) $customer->credit_balance, 0, ',', '.').'. Pilih uang kembali tunai.',
            ]]);
        }
        $customer->forceFill(['credit_balance' => round((float) $customer->credit_balance - $amount, 2)])->save();
    }
}
