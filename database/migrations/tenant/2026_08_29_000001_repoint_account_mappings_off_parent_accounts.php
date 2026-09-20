<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Memindahkan account mapping yang terlanjur menunjuk akun INDUK ke akun
     * leaf yang bisa diposting.
     *
     * Penyebabnya: `AccountMappingStorageService::defaultAccountIdForRequirement()`
     * dulu hanya memeriksa `is_active` dan tipe akun, sementara `setMapping()`
     * -- jalur yang dipakai user di halaman Pemetaan Akun -- menolak akun induk
     * dengan `ACCOUNT_NOT_POSTABLE`. Sync default karenanya memasang nilai yang
     * user sendiri tidak boleh pilih. Akibatnya bukan kosmetik:
     * `JournalValidationService` menolak posting ke akun induk, jadi setiap
     * jurnal yang memakai mapping itu gagal. Contoh nyata: template `trading`
     * memetakan `inventory.asset` ke 1130 Persediaan yang beranak 1131/1132.
     *
     * Guard leaf-nya sudah ditambahkan di service, tapi tenant yang sudah
     * terlanjur setup membawa nilai lamanya -- itu yang dibereskan di sini.
     *
     * Urutan pemilihan pengganti:
     *   1. kode berikutnya di `default_account_codes` milik mapping key itu,
     *      selama akunnya aktif, leaf, dan tipenya diizinkan;
     *   2. akun anak paling kecil kodenya yang aktif dan leaf dari akun induk
     *      yang lama -- 1130 jatuh ke 1131, 1100 jatuh ke 1100.02;
     *   3. kalau dua-duanya gagal, `account_id` dikosongkan supaya mapping-nya
     *      muncul sebagai wajib-diisi di setup wizard dan halaman Pemetaan Akun.
     *      Dibiarkan menunjuk akun induk jauh lebih buruk: posting gagal diam-diam
     *      di tengah transaksi, bukan di layar setup.
     */
    public function up(): void
    {
        $connection = DB::connection('tenant');

        if (! Schema::connection('tenant')->hasTable('account_mappings')
            || ! Schema::connection('tenant')->hasTable('chart_of_accounts')) {
            return;
        }

        $requirements = (array) config('account_mappings.required_mappings', []);

        $accounts = $connection->table('chart_of_accounts')
            ->get(['id', 'account_code', 'account_type', 'parent_account_id', 'is_active']);

        $childCount = [];
        foreach ($accounts as $account) {
            if ($account->parent_account_id !== null) {
                $parentId = (int) $account->parent_account_id;
                $childCount[$parentId] = ($childCount[$parentId] ?? 0) + 1;
            }
        }

        $byId = [];
        $byCode = [];
        foreach ($accounts as $account) {
            $byId[(int) $account->id] = $account;
            $byCode[(string) $account->account_code] = $account;
        }

        $isPostable = static function ($account, array $allowedTypes) use ($childCount): bool {
            if ($account === null || ! (bool) $account->is_active) {
                return false;
            }

            if (($childCount[(int) $account->id] ?? 0) > 0) {
                return false;
            }

            return $allowedTypes === [] || in_array((string) $account->account_type, $allowedTypes, true);
        };

        $mappings = $connection->table('account_mappings')
            ->whereNotNull('account_id')
            ->get(['id', 'mapping_key', 'account_id']);

        foreach ($mappings as $mapping) {
            $current = $byId[(int) $mapping->account_id] ?? null;

            if ($current === null || ($childCount[(int) $current->id] ?? 0) === 0) {
                continue;
            }

            $definition = $requirements[(string) $mapping->mapping_key] ?? [];
            $allowedTypes = array_map('strval', (array) ($definition['account_types'] ?? []));

            $replacement = null;

            foreach ((array) ($definition['default_account_codes'] ?? []) as $code) {
                $candidate = $byCode[(string) $code] ?? null;
                if ($isPostable($candidate, $allowedTypes)) {
                    $replacement = $candidate;
                    break;
                }
            }

            if ($replacement === null) {
                $children = array_filter(
                    $accounts->all(),
                    static fn ($a): bool => (int) ($a->parent_account_id ?? 0) === (int) $current->id
                );

                usort($children, static fn ($a, $b): int => strcmp((string) $a->account_code, (string) $b->account_code));

                foreach ($children as $child) {
                    if ($isPostable($child, $allowedTypes)) {
                        $replacement = $child;
                        break;
                    }
                }
            }

            $connection->table('account_mappings')
                ->where('id', $mapping->id)
                ->update([
                    'account_id' => $replacement !== null ? (int) $replacement->id : null,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Pembersihan data satu arah: nilai lamanya adalah akun induk yang
        // memang tidak boleh dipakai posting, jadi mengembalikannya hanya
        // memasang ulang mapping yang rusak.
    }
};
