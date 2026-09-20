<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kembalikan status aset non-depresiasi yang terlanjur ditandai
     * `fully_depreciated`.
     *
     * `syncLifecycleStatus()` dulu menyamakan "tidak punya jadwal tersisa"
     * dengan "sudah habis disusutkan". Aset kategori `none` (tanah, aset dalam
     * penyelesaian) dan `impairment_only` (goodwill) tidak pernah punya jadwal
     * sejak awal, jadi begitu diaktifkan lewat impor saldo awal statusnya
     * langsung jadi `fully_depreciated` — register mengklaim tanah sudah habis
     * nilainya padahal akumulasinya nol dan nilai bukunya utuh. Servicenya sudah
     * diperbaiki; baris yang terlanjur salah dibereskan di sini.
     *
     * Hanya angkanya yang salah label, bukan bukunya: tidak ada jurnal
     * penyusutan yang pernah terbit untuk aset ini, jadi tidak ada yang perlu
     * dibalik selain kolom `status`.
     *
     * Dua penjaga supaya tidak menyentuh aset yang memang benar-benar habis
     * disusutkan lalu kategorinya diubah ke non-depresiasi belakangan:
     * akumulasinya harus nol, dan tidak boleh ada jadwal yang sudah `posted`.
     * Aset yang sudah dilepas tidak disentuh sama sekali.
     */
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('fixed_assets')) {
            return;
        }

        $connection = DB::connection('tenant');

        $query = $connection->table('fixed_assets')
            ->where('status', 'fully_depreciated')
            ->whereNull('disposed_at')
            ->whereNotIn('depreciation_type', ['depreciation', 'amortization'])
            ->where(function ($q): void {
                $q->whereNull('accumulated_depreciation')->orWhere('accumulated_depreciation', '<=', 0);
            });

        if (Schema::connection('tenant')->hasTable('fixed_asset_depreciation_schedules')) {
            $query->whereNotExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('fixed_asset_depreciation_schedules')
                    ->whereColumn('fixed_asset_depreciation_schedules.fixed_asset_id', 'fixed_assets.id')
                    ->where('fixed_asset_depreciation_schedules.status', 'posted');
            });
        }

        // Sebagian aset dilepas per unit sebelum sempat ditandai. `disposed_at`
        // masih null untuk kasus itu, jadi statusnya dikembalikan ke
        // `partially_disposed`, bukan `active`.
        (clone $query)->whereColumn('remaining_quantity', '<', 'quantity')
            ->update(['status' => 'partially_disposed', 'updated_at' => now()]);

        (clone $query)->update(['status' => 'active', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Pembersihan data satu arah: menandai ulang tanah sebagai
        // `fully_depreciated` hanya mengembalikan status yang salah.
    }
};
