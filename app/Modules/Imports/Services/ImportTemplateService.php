<?php

namespace App\Modules\Imports\Services;

use App\Modules\FixedAssets\Models\FixedAssetCategory;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Project;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\Schema;

class ImportTemplateService
{
    /**
     * Isi templat unduhan sebuah profil: header kolom apa adanya plus baris
     * contoh. Nama berkas sengaja tanpa ekstensi -- `ExcelExportService`
     * yang menambahkan `.xlsx` saat men-stream.
     *
     * @return array{filename:string, headers:list<string>, rows:list<list<string>>, fields:list<string>, reference:list<array{field:?string, title:string, headers:list<string>, rows:list<list<string>>}>, enums:array<string, list<string>>}
     */
    public function template(string $profile): array
    {
        $profileConfig = $this->profile($profile);

        // 'samples' (array of arrays) dipakai untuk profil yang butuh
        // beberapa baris contoh — mis. jurnal umum (debit + kredit).
        // 'sample' (array tunggal) tetap didukung untuk backward compatibility.
        $rows = [];
        if (isset($profileConfig['samples']) && is_array($profileConfig['samples'])) {
            foreach ($profileConfig['samples'] as $sampleRow) {
                $rows[] = (array) $sampleRow;
            }
        } elseif (isset($profileConfig['sample'])) {
            $rows[] = (array) $profileConfig['sample'];
        }

        return [
            'filename' => 'template-'.$profile,
            'headers' => (array) $profileConfig['headers'],
            'fields' => (array) ($profileConfig['fields'] ?? []),
            'rows' => $rows,
            'reference' => $this->reference($profile),
            'enums' => $this->enums($profile),
        ];
    }

    /**
     * Daftar master data yang ikut diunduh sebagai sheet kedua.
     *
     * Alasannya: kolom seperti Category atau Account Code WAJIB cocok dengan
     * master data di sistem, tapi templat lama cuma memberi satu contoh di
     * baris pertama dan tidak ada cara melihat daftar lengkapnya tanpa membuka
     * aplikasi di tab lain. Sheet ini yang dijadikan sumber dropdown oleh
     * `ExcelExportService::downloadTemplate()`, jadi kolom yang harus cocok
     * dipilih, bukan diketik.
     *
     * Kode, bukan id: id auto-increment tidak berarti apa-apa bagi manusia,
     * tidak sama antar tenant, dan salah ketik satu digit tetap menghasilkan id
     * yang sah — persis kesalahan yang tidak bisa ditangkap validasi mana pun.
     * Salah ketik kode gagal berisik di pratinjau.
     *
     * @return list<array{field:?string, title:string, headers:list<string>, rows:list<list<string>>}>
     */
    private function reference(string $profile): array
    {
        return match ($profile) {
            'fixed_asset_opening' => array_values(array_filter([
                $this->block('category', 'Kategori Aset Tetap', ['Kode', 'Nama', 'Umur Default (thn)'], fn (): array => FixedAssetCategory::query()
                    ->where('is_active', true)
                    ->orderBy('code')
                    ->get(['code', 'name', 'default_useful_life_years'])
                    ->map(fn (FixedAssetCategory $category): array => [
                        (string) $category->code,
                        (string) $category->name,
                        $category->default_useful_life_years ? (string) $category->default_useful_life_years : '-',
                    ])->all()),
                $this->block('department', 'Departemen', ['Kode', 'Nama'], fn (): array => Department::query()
                    ->where('is_active', true)
                    ->orderBy('code')
                    ->get(['code', 'name'])
                    ->map(fn (Department $department): array => [(string) $department->code, (string) $department->name])
                    ->all()),
                $this->block('project', 'Proyek', ['Kode', 'Nama'], fn (): array => Project::query()
                    ->where('is_active', true)
                    ->where('status', 'active')
                    ->orderBy('code')
                    ->get(['code', 'name'])
                    ->map(fn (Project $project): array => [(string) $project->code, (string) $project->name])
                    ->all()),
            ])),
            'opening_balance' => array_values(array_filter([
                // Akun induk sengaja tidak masuk daftar: committer menolaknya
                // (saldo hanya boleh di akun terbawah), jadi menawarkannya di
                // dropdown cuma memancing baris yang pasti gagal.
                $this->block('account_code', 'Akun (hanya akun terbawah)', ['Kode', 'Nama'], fn (): array => ChartOfAccount::query()
                    ->where('is_active', true)
                    ->whereDoesntHave('children')
                    ->orderBy('account_code')
                    ->get(['account_code', 'account_name'])
                    ->map(fn (ChartOfAccount $account): array => [(string) $account->account_code, (string) $account->account_name])
                    ->all()),
            ])),
            default => [],
        };
    }

    /**
     * Pilihan tetap yang tidak perlu master data — cukup ditulis langsung ke
     * aturan validasi sel.
     *
     * @return array<string, list<string>>
     */
    private function enums(string $profile): array
    {
        return match ($profile) {
            // Kelompok masa manfaat pajak. Sejajar dengan aturan `in:` di
            // `StoreFixedAssetRequest` dan `FixedAssetOpeningImportCommitter`.
            'fixed_asset_opening' => ['useful_life_years' => ['4', '8', '10', '16', '20']],
            default => [],
        };
    }

    /**
     * Satu blok daftar referensi, atau null kalau tabelnya belum ada / kosong.
     * Blok kosong dibuang, bukan ditulis sebagai judul tanpa isi: dropdown yang
     * menunjuk ke rentang kosong ditolak Excel dan merusak seluruh berkasnya.
     *
     * @param  callable(): list<list<string>>  $loader
     * @param  list<string>  $headers
     * @return array{field:?string, title:string, headers:list<string>, rows:list<list<string>>}|null
     */
    private function block(?string $field, string $title, array $headers, callable $loader): ?array
    {
        $table = match ($field) {
            'category' => 'fixed_asset_categories',
            'department' => 'departments',
            'project' => 'projects',
            'account_code' => 'chart_of_accounts',
            default => null,
        };

        if ($table !== null && ! Schema::connection('tenant')->hasTable($table)) {
            return null;
        }

        $rows = $loader();

        return $rows === [] ? null : [
            'field' => $field,
            'title' => $title,
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    private function profile(string $profile): array
    {
        $profiles = (array) config('imports.profiles', []);

        if (! array_key_exists($profile, $profiles)) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                'Profil impor tidak dikenal.',
                422,
                ['profile' => ['Profil impor tidak dikenal.']]
            );
        }

        return (array) $profiles[$profile];
    }
}
