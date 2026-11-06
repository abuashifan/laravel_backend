<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosProduct;
use App\Modules\Pos\Models\PosStockCount;
use App\Shared\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stok opname: hasil hitung fisik menggantikan stok sistem, selisihnya
 * dicatat (jumlah dan nilai modal) supaya pemilik tahu berapa barang hilang.
 */
class PosStockCountService
{
    public function __construct(
        private readonly PosStockService $stock,
        private readonly PosNumberService $numbers,
    ) {}

    /** @param  array<string,mixed>  $data */
    public function create(User $user, array $data): PosStockCount
    {
        return DB::connection('pos')->transaction(function () use ($user, $data) {
            $count = PosStockCount::query()->create([
                'number' => $this->numbers->next(PosStockCount::class, 'SO'),
                'counted_at' => now(),
                'user_id' => $user->id,
                'user_name' => $user->name,
                'notes' => $data['notes'] ?? null,
            ]);

            $value = 0.0;
            $seen = [];
            foreach ((array) $data['lines'] as $index => $input) {
                $productId = (int) $input['product_id'];
                if (isset($seen[$productId])) {
                    throw ValidationException::withMessages(["lines.{$index}.product_id" => ['Produk dihitung dua kali.']]);
                }
                $seen[$productId] = true;

                /** @var PosProduct|null $product */
                $product = PosProduct::query()->lockForUpdate()->find($productId);
                if (! $product || ! $product->track_stock) {
                    throw ValidationException::withMessages(["lines.{$index}.product_id" => ['Produk tidak ditemukan atau stoknya tidak dilacak.']]);
                }

                $system = round((float) $product->stock, 3);
                $counted = round((float) $input['counted_quantity'], 3);
                $difference = round($counted - $system, 3);
                $value += $difference * (float) $product->cost;

                $count->lines()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'system_quantity' => $system,
                    'counted_quantity' => $counted,
                    'difference' => $difference,
                    'unit_cost' => (float) $product->cost,
                ]);

                $this->stock->move($product, $difference, 'count', 'pos_stock_count', $count->id, $count->number, $user->id);
            }

            $count->forceFill(['difference_value' => round($value, 2)])->save();

            return $count->load('lines');
        });
    }
}
