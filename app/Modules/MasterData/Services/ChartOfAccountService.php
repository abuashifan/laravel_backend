<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Services\Concerns\ParsesBooleanFilters;
use App\Shared\Api\AppliesListQuery;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ChartOfAccountService
{
    use AppliesListQuery;
    use ParsesBooleanFilters;

    /**
     * Batas kedalaman pohon akun yang didukung daftar.
     *
     * Rekursi di `hierarchicalQuery()` WAJIB punya batas, kalau tidak siklus
     * induk (A->B->A) membuatnya berputar tanpa henti. Konsekuensinya baris di
     * luar batas ini akan LENYAP dari daftar -- itulah kenapa `create()` dan
     * `update()` menolak kedalaman berlebih lewat `assertDepthWithinLimit()`.
     * Jangan naikkan salah satunya tanpa yang lain.
     */
    private const MAX_DEPTH = 10;

    protected array $listSearchable = ['account_code', 'account_name'];

    protected array $listSearchableRelations = [];

    /** Master data tidak punya tanggal dokumen; daftarnya juga tidak menyediakan filter periode. */
    protected string $listDateColumn = '';

    /** Boolean, bukan string -- `?status=active|inactive` dipetakan di trait. */
    protected string $listStatusColumn = 'is_active';

    protected array $listDefaultSort = ['account_code' => 'asc'];

    protected array $listSortable = ['account_code', 'account_name', 'account_type', 'is_active'];

    /**
     * Daftar akun, terpaginasi tapi tetap terbaca sebagai hierarki.
     *
     * Baris diratakan lalu diurut pre-order (induk, lalu keturunannya), dan
     * tiap baris membawa `depth`-nya sendiri supaya frontend bisa memberi
     * indentasi walau induknya berada di halaman lain -- persis perilaku
     * aplikasi acuan.
     *
     * Mode hierarkis DILEWATI saat pencarian atau sort kolom aktif: hasil
     * pencarian adalah himpunan tersebar, jadi menampilkan anak menjorok
     * sementara induknya tidak di layar justru menyesatkan. Dalam mode datar
     * `depth` tidak dikirim dan frontend memakai indentasi 0.
     *
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator|Collection<int,ChartOfAccount>
     */
    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $sortBy = (string) ($filters['sort_by'] ?? $filters['sort'] ?? '');

        // Filter kolom terpisah untuk dialog pemilih akun: di sana "No Akun"
        // dan "Nama Akun" adalah dua kotak berbeda dan harus di-AND-kan.
        // `search` yang ada bersifat OR antar kedua kolom, jadi tidak bisa
        // dipakai untuk mempersempit keduanya sekaligus.
        $accountCode = trim((string) ($filters['account_code'] ?? ''));
        $accountName = trim((string) ($filters['account_name'] ?? ''));

        // Sama seperti pencarian: begitu hasilnya himpunan tersebar, mode
        // hierarkis dimatikan supaya tidak ada anak menjorok tanpa induknya.
        $hierarkis = trim((string) ($filters['search'] ?? '')) === ''
            && $accountCode === ''
            && $accountName === ''
            && ! in_array($sortBy, $this->listSortable, true);

        $query = $hierarkis ? $this->hierarchicalQuery() : ChartOfAccount::query();

        if ($accountCode !== '') {
            $query->where('account_code', 'like', "%{$accountCode}%");
        }

        if ($accountName !== '') {
            $query->where('account_name', 'like', "%{$accountName}%");
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $this->toBool($filters['is_active']));
        }

        if (! empty($filters['account_type'])) {
            $query->where('account_type', (string) $filters['account_type']);
        }

        if (array_key_exists('is_cash_bank', $filters)) {
            if ($this->toBool($filters['is_cash_bank'])) {
                // Bukan kolom literal -- lihat ChartOfAccount::effectiveCashBankIds():
                // akun anak yang tidak ditandai satu-satu ikut cash/bank kalau
                // salah satu induknya ditandai.
                $query->whereIn('chart_of_accounts.id', ChartOfAccount::effectiveCashBankIds());
            } else {
                $query->where('is_cash_bank', false);
            }
        }

        // Dipakai pemilih akun transaksi (AccountPickerDialog, form kas/bank,
        // baris jurnal) untuk menyembunyikan akun induk -- akun induk hanya
        // untuk rekap saldo di laporan, tidak boleh diposting transaksi.
        //
        // Sengaja BUKAN whereDoesntHave('children'): itu correlated subquery
        // yang butuh Eloquent mengenali self-join lewat pembanding `from`
        // outer vs inner. `hierarchicalQuery()` memakai `fromRaw()` (raw
        // Expression), jadi perbandingan itu selalu tidak cocok dengan `from`
        // biasa milik relasi -- Eloquent gagal memberi alias `laravel_reserved_N`
        // dan subquery-nya jadi merujuk dirinya sendiri (SQLite tidak
        // mengoreksinya jadi outer reference), sehingga NOT EXISTS selalu
        // true dan filternya diam-diam tidak berefek. `whereNotIn` dengan
        // subquery TIDAK berkorelasi menghindari masalah itu sama sekali.
        if ($this->toBool($filters['postable_only'] ?? false)) {
            $query->whereNotIn('chart_of_accounts.id', function ($sub) {
                $sub->select('parent_account_id')
                    ->from('chart_of_accounts')
                    ->whereNotNull('parent_account_id');
            });
        }

        if ($hierarkis) {
            // Dipasang SEBELUM applyListQuery supaya jadi kunci urut pertama;
            // `$listDefaultSort` (account_code) menyusul sebagai kunci kedua
            // dan tidak berbahaya karena hierarchy_path unik.
            $query->orderBy('hierarchy_path');
        }

        return $this->applyListQuery($query, $filters);
    }

    /**
     * Query dasar yang menambahkan `depth` dan `hierarchy_path` lewat recursive
     * CTE, tanpa kolom turunan tersimpan di tabel.
     *
     * Kenapa dihitung tiap query, bukan disimpan sebagai kolom: ada beberapa
     * jalur tulis di luar service ini (dua seeder memakai raw `upsert()`, plus
     * factory), jadi kolom turunan akan basi diam-diam. Dan `account_code`
     * belum divalidasi hierarkis, jadi menyimpan path turunan dari kode itu
     * berarti menanam bug kaskade sebelum aturannya ada.
     *
     * Tiga detail yang WAJIB dipertahankan:
     *
     * 1. Alias `chart_of_accounts` -- supaya `where()`/`orderBy()` dari
     *    `AppliesListQuery` tetap resolve tanpa kualifikasi nama tabel.
     * 2. Pemisah `char(1)`, BUKAN titik. Dengan titik, root `1101.02` menyelip
     *    di antara `1101` dan anaknya `1101.5` (byte `.` = 0x2E lebih tinggi
     *    dari `0`..`9`). `char(1)` = 0x01 lebih rendah dari karakter apa pun
     *    yang wajar muncul di kode akun, jadi keturunan selalu menempel pada
     *    induknya. Diverifikasi lewat perbandingan langsung kedua pemisah.
     * 3. Anchor `OR parent_account_id NOT IN (...)` -- menjaga baris yatim
     *    (induk sudah terhapus) tetap muncul sebagai root, bukan hilang.
     *
     * Portabilitas: CTE-nya standar (MySQL 8+, MariaDB 10.2+, Postgres). Kalau
     * tenant pindah dari SQLite, collation perlu ditinjau -- di MySQL
     * `utf8mb4_*_ci` urutan path bisa berbeda dan butuh
     * `ORDER BY hierarchy_path COLLATE utf8mb4_bin`.
     *
     * @return Builder<ChartOfAccount>
     */
    private function hierarchicalQuery(): Builder
    {
        $maxDepth = self::MAX_DEPTH;

        // Raw tanpa binding, supaya urutan binding milik trait tidak bergeser.
        $cte = <<<SQL
        (WITH RECURSIVE coa_tree AS (
            SELECT a.*, 0 AS depth, a.account_code AS hierarchy_path
            FROM chart_of_accounts a
            WHERE a.parent_account_id IS NULL
               OR a.parent_account_id NOT IN (SELECT id FROM chart_of_accounts)
            UNION ALL
            SELECT c.*, t.depth + 1, t.hierarchy_path || char(1) || c.account_code
            FROM chart_of_accounts c
            INNER JOIN coa_tree t ON c.parent_account_id = t.id
            WHERE t.depth < {$maxDepth}
        ) SELECT * FROM coa_tree) as chart_of_accounts
        SQL;

        return ChartOfAccount::query()->fromRaw($cte);
    }

    public function create(array $data): ChartOfAccount
    {
        $accountType = (string) $data['account_type'];
        $data['normal_balance'] = $this->validateNormalBalance($accountType, $data['normal_balance'] ?? null);

        $this->validateCashBank($accountType, (bool) ($data['is_cash_bank'] ?? false));

        $code = (string) $data['account_code'];
        if (ChartOfAccount::query()->where('account_code', $code)->exists()) {
            throw ApiException::make('DUPLICATE_ACCOUNT_CODE', 'Account code is already in use.', 422, [
                'account_code' => ['Account Code is already in use.'],
            ]);
        }

        if (! empty($data['parent_account_id'])) {
            $parentId = (int) $data['parent_account_id'];
            if (! ChartOfAccount::query()->whereKey($parentId)->exists()) {
                throw ApiException::make('PARENT_ACCOUNT_NOT_FOUND', 'Parent account not found.', 422);
            }
            $this->assertDepthWithinLimit($parentId);
        }

        return ChartOfAccount::query()->create($data);
    }

    public function update(ChartOfAccount $account, array $data): ChartOfAccount
    {
        $accountType = (string) ($data['account_type'] ?? $account->account_type);

        if (array_key_exists('normal_balance', $data) || array_key_exists('account_type', $data)) {
            $data['normal_balance'] = $this->validateNormalBalance($accountType, $data['normal_balance'] ?? $account->normal_balance);
        }

        $this->validateCashBank($accountType, (bool) ($data['is_cash_bank'] ?? $account->is_cash_bank));

        if (! empty($data['account_code']) && $data['account_code'] !== $account->account_code) {
            if (ChartOfAccount::query()->where('account_code', (string) $data['account_code'])->exists()) {
                throw ApiException::make('DUPLICATE_ACCOUNT_CODE', 'Account code is already in use.', 422, [
                    'account_code' => ['Account Code is already in use.'],
                ]);
            }
        }

        if (array_key_exists('parent_account_id', $data)) {
            $parentId = $data['parent_account_id'];
            if ($parentId !== null && (int) $parentId === (int) $account->id) {
                throw ApiException::make('INVALID_PARENT_ACCOUNT', 'parent_account_id cannot be self.', 422);
            }
            if ($parentId !== null && ! ChartOfAccount::query()->whereKey((int) $parentId)->exists()) {
                throw ApiException::make('PARENT_ACCOUNT_NOT_FOUND', 'Parent account not found.', 422);
            }
            if ($parentId !== null) {
                $this->assertNoParentCycle((int) $account->id, (int) $parentId);
                $this->assertDepthWithinLimit((int) $parentId);
            }
        }

        $account->fill($data);
        $account->save();

        return $account->refresh();
    }

    public function deactivate(ChartOfAccount $account): ChartOfAccount
    {
        if ($account->children()->where('is_active', true)->exists()) {
            throw ApiException::make('ACCOUNT_HAS_ACTIVE_CHILDREN', 'Cannot deactivate account with active child accounts.', 422);
        }

        $account->is_active = false;
        $account->save();

        return $account->refresh();
    }

    public function activate(ChartOfAccount $account): ChartOfAccount
    {
        $account->is_active = true;
        $account->save();

        return $account->refresh();
    }

    /**
     * Menolak siklus induk (A -> B -> A).
     *
     * Bukan sekadar kebersihan data: di `hierarchicalQuery()` anggota siklus
     * tidak terjangkau dari anchor, jadi mereka LENYAP dari daftar. Sebelum
     * paginasi server-side hal ini juga sudah terjadi (buildTree di frontend
     * pun tidak pernah merender anggota siklus), tapi dulu tidak terlihat;
     * sekarang ia akan tampak sebagai selisih `total` yang tidak bisa
     * dijelaskan.
     */
    private function assertNoParentCycle(int $accountId, int $parentId): void
    {
        $cursor = $parentId;
        $langkah = 0;

        while ($cursor !== 0 && $langkah <= self::MAX_DEPTH + 1) {
            if ($cursor === $accountId) {
                throw ApiException::make(
                    'INVALID_PARENT_ACCOUNT',
                    'parent_account_id would create a cycle in the account hierarchy.',
                    422,
                );
            }

            $cursor = (int) (ChartOfAccount::query()->whereKey($cursor)->value('parent_account_id') ?? 0);
            $langkah++;
        }
    }

    /**
     * Menolak kedalaman melebihi `MAX_DEPTH`, karena baris di luar batas itu
     * tidak akan pernah muncul di daftar (rekursi berhenti di sana).
     */
    private function assertDepthWithinLimit(int $parentId): void
    {
        $kedalamanInduk = 0;
        $cursor = $parentId;

        while ($cursor !== 0 && $kedalamanInduk <= self::MAX_DEPTH + 1) {
            $cursor = (int) (ChartOfAccount::query()->whereKey($cursor)->value('parent_account_id') ?? 0);
            $kedalamanInduk++;
        }

        // Anak baru akan berada di kedalaman $kedalamanInduk (induk 0-indexed).
        if ($kedalamanInduk >= self::MAX_DEPTH) {
            throw ApiException::make(
                'MAX_ACCOUNT_DEPTH_EXCEEDED',
                'Account hierarchy is limited to '.self::MAX_DEPTH.' levels.',
                422,
            );
        }
    }

    public function validateNormalBalance(string $accountType, ?string $normalBalance): string
    {
        $accountType = trim($accountType);
        $normalBalance = $normalBalance ? trim($normalBalance) : null;

        if ($normalBalance && in_array($normalBalance, ['debit', 'credit'], true)) {
            return $normalBalance;
        }

        return match ($accountType) {
            'asset', 'expense' => 'debit',
            'liability', 'equity', 'revenue' => 'credit',
            default => 'debit',
        };
    }

    public function validateCashBank(string $accountType, bool $isCashBank): void
    {
        if ($isCashBank && $accountType !== 'asset') {
            throw ApiException::make('INVALID_CASH_BANK_ACCOUNT_TYPE', 'is_cash_bank is only allowed for asset accounts.', 422);
        }
    }
}
