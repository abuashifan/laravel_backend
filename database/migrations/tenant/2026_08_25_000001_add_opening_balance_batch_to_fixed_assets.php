<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cap batch saldo awal yang membukukan sebuah aset tetap awal.
 *
 * Tanpa kolom ini, `OpeningBalanceBatchService::fixedAssetSystemLines()`
 * menjumlahkan SELURUH aset ber-`source_type = 'opening_import'` tanpa peduli
 * batch mana. Begitu ada batch koreksi (batch kedua untuk aset yang terlewat),
 * batch itu akan membukukan ulang seluruh aset batch pertama.
 *
 * Aturannya: batch yang belum diposting melihat aset yang BELUM bercap; batch
 * yang sudah diposting melihat aset yang bercap dirinya. Cap dibubuhkan saat
 * posting dan dilepas saat batch dibuka kembali (reopen).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('fixed_assets')) {
            return;
        }

        Schema::connection('tenant')->table('fixed_assets', function (Blueprint $table) {
            $table->foreignId('opening_balance_batch_id')
                ->nullable()
                ->after('source_id')
                ->constrained('opening_balance_batches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable('fixed_assets')) {
            return;
        }

        Schema::connection('tenant')->table('fixed_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opening_balance_batch_id');
        });
    }
};
