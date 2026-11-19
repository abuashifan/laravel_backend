<?php

namespace App\Modules\Gudang;

use App\Shared\Permission\PermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BatchStock
{
    public function enable(object $item, int $actor): void
    {
        $db = DB::connection('gudang');
        if (! $db->table('gudang_stock_movements')->where('item_id', $item->id)->exists()) {
            return;
        }
        $locations = $db->table('gudang_stock_movements')->where('item_id', $item->id)->distinct()->pluck('location_id')->all();
        app(WarehouseAccess::class)->requireLocations($locations);
        abort_unless(app(PermissionService::class)->can('gudang.stock.approve'), 403);
        $this->check(! $db->table('gudang_stock_balances')->where('item_id', $item->id)->where('quantity_milli', '<', 0)->exists(), 'Selesaikan saldo negatif sebelum mengaktifkan pelacakan batch.');
        $batchId = $db->table('gudang_batches')->insertGetId([
            'item_id' => $item->id, 'code' => 'HIST-'.$item->id, 'status' => 'quarantine',
        ]);
        $balances = $db->table('gudang_stock_balances')->where('item_id', $item->id)->get();
        foreach ($balances as $balance) {
            $db->table('gudang_batch_balances')->insert(['batch_id' => $batchId, 'location_id' => $balance->location_id, 'quantity_milli' => $balance->quantity_milli]);
        }
        $db->table('gudang_stock_movements')->where('item_id', $item->id)->orderBy('id')->chunkById(1000, function ($movements) use ($db, $batchId) {
            $db->table('gudang_movement_batches')->insert($movements->map(fn ($movement) => [
                'movement_id' => $movement->id, 'batch_id' => $batchId, 'quantity_milli' => $movement->quantity_milli,
            ])->all());
        });
        $db->table('gudang_batch_actions')->insert([
            'batch_id' => $batchId, 'user_id' => $actor, 'status' => 'quarantine',
            'note' => 'Saldo dan mutasi sebelum pelacakan batch. Periksa kondisi serta identitas batch sebelum pelepasan.', 'created_at' => now(),
        ]);
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['batch_id' => $message]);
        }
    }

    public function record(int $document, int $movement, object $item, int $location, int $amount, ?int $batchId, array $allocations): array
    {
        $db = DB::connection('gudang');
        if (! $item->batch_enabled) {
            $this->check($batchId === null && $allocations === [], 'Barang ini memakai stok tanpa batch.');

            return [];
        }
        $type = $db->table('gudang_stock_documents')->where('id', $document)->value('type');
        $kind = $db->table('gudang_locations')->where('id', $location)->value('kind');
        $saleable = $amount < 0 && in_array($type, ['issue', 'transfer', 'putaway'], true)
            && in_array($kind, ['simpan', 'terima'], true);
        if ($allocations === [] && $batchId !== null) {
            $allocations = [['batch_id' => $batchId, 'quantity_milli' => $amount]];
        }
        if ($allocations === [] && $amount < 0) {
            $available = $db->table('gudang_batch_balances as balance')->join('gudang_batches as batch', 'batch.id', '=', 'balance.batch_id')
                ->where('batch.item_id', $item->id)->where('balance.location_id', $location)->where('balance.quantity_milli', '>', 0)
                ->when($saleable, fn ($query) => $query->where('batch.status', 'active')
                    ->where(fn ($dates) => $dates->whereNull('batch.expires_at')->orWhere('batch.expires_at', '>=', now('Asia/Jakarta')->toDateString())))
                ->orderByRaw('case when batch.expires_at is null then 1 else 0 end')->orderBy('batch.expires_at')->orderBy('batch.id')
                ->get(['batch.id', 'balance.quantity_milli']);
            $remaining = -$amount;
            foreach ($available as $batch) {
                $take = min($remaining, (int) $batch->quantity_milli);
                $allocations[] = ['batch_id' => (int) $batch->id, 'quantity_milli' => -$take];
                $remaining -= $take;
                if ($remaining === 0) {
                    break;
                }
            }
            $this->check($remaining === 0, 'Saldo batch yang dapat digunakan tidak mencukupi.');
        }
        $this->check(array_sum(array_column($allocations, 'quantity_milli')) === $amount, 'Penerimaan barang batch memerlukan batch dan jumlah yang sesuai.');
        foreach ($allocations as $allocation) {
            $quantity = (int) $allocation['quantity_milli'];
            $this->check(($quantity > 0) === ($amount > 0), 'Arah mutasi batch tidak sesuai.');
            $batch = $db->table('gudang_batches')->where('id', $allocation['batch_id'])->where('item_id', $item->id)->first();
            $this->check($batch !== null, 'Batch tidak sesuai dengan barang.');
            if ($saleable) {
                $this->check($batch->status === 'active' && ($batch->expires_at === null || $batch->expires_at >= now('Asia/Jakarta')->toDateString()), 'Batch karantina atau kedaluwarsa dibatasi dari pengeluaran normal.');
            }
            $key = ['batch_id' => $batch->id, 'location_id' => $location];
            $db->table('gudang_batch_balances')->insertOrIgnore($key);
            $balance = $db->table('gudang_batch_balances')->where($key);
            if ($quantity < 0) {
                $balance->where('quantity_milli', '>=', -$quantity);
            }
            $this->check($balance->increment('quantity_milli', $quantity) === 1, 'Saldo batch tidak mencukupi.');
            $db->table('gudang_movement_batches')->insert(['movement_id' => $movement, 'batch_id' => $batch->id, 'quantity_milli' => $quantity]);
        }

        return $allocations;
    }

    public function transit(int $document, int $item, int $quantity): array
    {
        $db = DB::connection('gudang');
        if (! $db->table('gudang_items')->where('id', $item)->value('batch_enabled')) {
            return [];
        }
        $pending = $db->table('gudang_movement_batches as batch')->join('gudang_stock_movements as movement', 'movement.id', '=', 'batch.movement_id')
            ->where('movement.document_id', $document)->where('movement.item_id', $item)->groupBy('batch.batch_id')
            ->havingRaw('sum(batch.quantity_milli) < 0')->orderBy('batch.batch_id')
            ->selectRaw('batch.batch_id, -sum(batch.quantity_milli) as remaining')->get();
        $allocations = [];
        foreach ($pending as $batch) {
            $take = min($quantity, (int) $batch->remaining);
            if ($take > 0) {
                $allocations[] = ['batch_id' => (int) $batch->batch_id, 'quantity_milli' => $take];
                $quantity -= $take;
            }
        }
        $this->check($quantity === 0, 'Rincian batch dalam perjalanan tidak sesuai.');

        return $allocations;
    }

    public function inverse(int $document, int $item, int $location): array
    {
        return DB::connection('gudang')->table('gudang_movement_batches as batch')->join('gudang_stock_movements as movement', 'movement.id', '=', 'batch.movement_id')
            ->where('movement.document_id', $document)->where('movement.item_id', $item)->where('movement.location_id', $location)
            ->groupBy('batch.batch_id')->havingRaw('sum(batch.quantity_milli) <> 0')->orderBy('batch.batch_id')
            ->selectRaw('batch.batch_id, -sum(batch.quantity_milli) as quantity_milli')->get()
            ->map(fn ($row) => ['batch_id' => (int) $row->batch_id, 'quantity_milli' => (int) $row->quantity_milli])->all();
    }
}
