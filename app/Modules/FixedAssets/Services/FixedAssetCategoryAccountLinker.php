<?php

namespace App\Modules\FixedAssets\Services;

use App\Modules\FixedAssets\Models\FixedAssetCategory;
use App\Modules\MasterData\Models\AccountMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menyambungkan kategori aset tetap default ke akun COA-nya.
 *
 * Kategorinya sendiri BUKAN urusan kelas ini: 15 kategori default sudah
 * ditanam migration tenant `2026_06_15_000001_create_fixed_asset_tables.php`
 * saat tabelnya dibuat. Yang tidak dikerjakan migration itu adalah mengisi
 * kolom `*_account_id` -- ia jalan sebelum satu akun COA pun ada, jadi tidak
 * ada yang bisa ditunjuk.
 *
 * Akibat kolom itu dibiarkan null: `FixedAssetService::assetAccount()` dkk
 * selalu jatuh ke mapping generik, sehingga 12 kunci mapping per kelas
 * (kendaraan/gedung/peralatan/software) dari commit 3e6bcbb tidak pernah
 * menggerakkan jurnal. Kelas ini yang menutupnya, dipanggil
 * `CoaTemplateService::applyTemplate()` SETELAH akun dibuat dan
 * `syncDefaultMappingsFromConfig()` selesai.
 *
 * Idempoten dan tidak merusak: dipanggil ulang (mis. user mengganti template
 * COA di tengah setup) hanya MENGISI kolom yang masih null, tidak pernah
 * menimpa akun yang sudah disetel user di halaman Kategori Aset Tetap. Pola
 * "hanya isi yang kosong" yang sama dengan `syncDefaultMappingsFromConfig()`.
 */
class FixedAssetCategoryAccountLinker
{
    /**
     * @return int jumlah kategori yang kolom akunnya bertambah terisi
     */
    public function linkDefaults(): int
    {
        if (! Schema::connection('tenant')->hasTable('fixed_asset_categories')) {
            return 0;
        }

        $shared = (array) config('fixed_asset_categories.shared_accounts', []);
        $links = (array) config('fixed_asset_categories.account_links', []);
        $touched = 0;

        DB::connection('tenant')->transaction(function () use ($links, $shared, &$touched) {
            foreach (FixedAssetCategory::query()->get() as $category) {
                $accountKeys = array_merge(
                    (array) ($links[(string) $category->code] ?? []),
                    $shared,
                );

                if ($accountKeys === []) {
                    continue;
                }

                $fill = [];
                foreach ($this->resolveAccounts($accountKeys) as $column => $accountId) {
                    if ($accountId !== null && $category->{$column} === null) {
                        $fill[$column] = $accountId;
                    }
                }

                if ($fill !== []) {
                    $category->forceFill($fill)->save();
                    $touched++;
                }
            }
        });

        return $touched;
    }

    /**
     * Resolve daftar kunci mapping jadi id akun -- kunci pertama yang punya
     * akun aktif menang, sisanya diabaikan.
     *
     * @param  array<string, list<string>>  $accountKeys
     * @return array<string, int|null>
     */
    private function resolveAccounts(array $accountKeys): array
    {
        $resolved = [];

        foreach ($accountKeys as $column => $keys) {
            $resolved[$column] = null;

            foreach ((array) $keys as $key) {
                $accountId = AccountMapping::query()
                    ->where('mapping_key', (string) $key)
                    ->where('is_active', true)
                    ->value('account_id');

                if ($accountId !== null) {
                    $resolved[$column] = (int) $accountId;
                    break;
                }
            }
        }

        return $resolved;
    }
}
