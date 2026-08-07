<?php

namespace Tests\Feature\CashBank;

use App\Modules\CashBank\Models\BankReconciliation;
use App\Modules\CashBank\Models\BankTransfer;
use App\Modules\CashBank\Models\CashPayment;
use App\Modules\CashBank\Models\CashReceipt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Journal\JournalTestCase;

/**
 * Menguji 4 endpoint daftar Kas & Bank setelah service-nya pindah ke SQL lewat
 * `AppliesListQuery` (Fase 4 / list-query-pushdown). Pola sama dengan
 * `SalesListQueryTest` dan `PurchaseListQueryTest`.
 *
 * Modul ini tidak punya pencarian lewat relasi (00-conventions.md 4), jadi
 * yang diuji cuma kolom sendiri. Yang khas di sini:
 *
 * - Kolom keterangan bernama `notes`, bukan `description` seperti tertulis di
 *   rencana awal -- kolom itu tidak pernah ada di tabelnya.
 * - `BankReconciliation` memakai `statement_end_date` sebagai kolom tanggal,
 *   bukan `created_at`, dan halaman frontend-nya belum punya kotak pencarian.
 * - `BankTransfer` sengaja tidak menerima `cash_bank_account_id` (punya dua
 *   akun: from_ dan to_), berbeda dari tiga lainnya.
 */
class CashBankListQueryTest extends JournalTestCase
{
    /**
     * @return array<string,array{0:string,1:class-string,2:string,3:string,4:bool}>
     */
    public static function cashBankModules(): array
    {
        // [uri, model, kolom nomor, kolom tanggal, menerima cash_bank_account_id]
        return [
            'cash-receipts' => ['/api/cash-bank/cash-receipts', CashReceipt::class, 'receipt_number', 'receipt_date', true],
            'cash-payments' => ['/api/cash-bank/cash-payments', CashPayment::class, 'payment_number', 'payment_date', true],
            'bank-transfers' => ['/api/cash-bank/bank-transfers', BankTransfer::class, 'transfer_number', 'transfer_date', false],
            'bank-reconciliations' => ['/api/cash-bank/bank-reconciliations', BankReconciliation::class, 'reconciliation_number', 'statement_end_date', true],
        ];
    }

    /**
     * Tiga dokumen berjenjang tanggalnya; dua di akun kas utama, satu di akun
     * kedua supaya filter `cash_bank_account_id` punya sesuatu untuk disaring.
     *
     * @param  class-string  $model
     * @return array{headers: array<string,string>, primary: int, secondary: int}
     */
    private function seedDocuments(string $model, string $numberColumn, string $dateColumn): array
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $primary = (int) $ctx['accounts']['debit'];
        $secondary = (int) $ctx['accounts']['credit'];

        $rows = [
            ['DOC-000001', '2026-01-10', 'draft', $primary],
            ['DOC-000002', '2026-02-15', 'posted', $primary],
            ['DOC-000003', '2026-03-20', 'posted', $secondary],
        ];
        foreach ($rows as [$number, $date, $status, $accountId]) {
            $attributes = [
                $numberColumn => $number,
                $dateColumn => $date,
                'status' => $status,
                'notes' => "Keterangan {$number}",
            ];

            if ($model === BankTransfer::class) {
                $attributes['from_cash_bank_account_id'] = $accountId;
                $attributes['to_cash_bank_account_id'] = $secondary;
                $attributes['amount'] = 100;
            } elseif ($model === BankReconciliation::class) {
                $attributes['cash_bank_account_id'] = $accountId;
                // statement_start_date wajib; end_date sudah diisi $dateColumn.
                $attributes['statement_start_date'] = $date;
            } else {
                $attributes['cash_bank_account_id'] = $accountId;
                $attributes['amount'] = 100;
            }

            $model::query()->create($attributes);
        }

        return ['headers' => $ctx['headers'], 'primary' => $primary, 'secondary' => $secondary];
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('cashBankModules')]
    public function test_search_matches_document_number(string $uri, string $model, string $numberColumn, string $dateColumn, bool $acceptsAccountFilter): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn);

        $res = $this->getJson("{$uri}?page=1&per_page=25&search=000002", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath("data.data.0.{$numberColumn}", 'DOC-000002');
    }

    /**
     * Kolomnya `notes`. Rekonsiliasi punya kolom `notes` juga tapi sengaja
     * tidak dicari (00-conventions.md 4 cuma menyetujui nomor untuk modul itu)
     * -- dikunci di sini supaya perbedaannya disengaja, bukan kelupaan.
     *
     * @param  class-string  $model
     */
    #[DataProvider('cashBankModules')]
    public function test_search_matches_notes_except_reconciliation(string $uri, string $model, string $numberColumn, string $dateColumn, bool $acceptsAccountFilter): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn);

        $res = $this->getJson("{$uri}?page=1&per_page=25&search=Keterangan+DOC-000003", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', $model === BankReconciliation::class ? 0 : 1);
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('cashBankModules')]
    public function test_search_does_not_leak_past_status_filter(string $uri, string $model, string $numberColumn, string $dateColumn, bool $acceptsAccountFilter): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn);

        $res = $this->getJson("{$uri}?page=1&per_page=25&search=DOC-&status=draft", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath("data.data.0.{$numberColumn}", 'DOC-000001');
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('cashBankModules')]
    public function test_cash_bank_account_filter_applies(string $uri, string $model, string $numberColumn, string $dateColumn, bool $acceptsAccountFilter): void
    {
        if (! $acceptsAccountFilter) {
            $this->markTestSkipped('BankTransfer punya dua akun (from_/to_); filter tunggal ambigu.');
        }

        ['headers' => $headers, 'primary' => $primary] = $this->seedDocuments($model, $numberColumn, $dateColumn);

        $res = $this->getJson("{$uri}?page=1&per_page=25&cash_bank_account_id={$primary}", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 2);
        $numbers = collect($res->json('data.data'))->pluck($numberColumn)->sort()->values()->all();
        $this->assertSame(['DOC-000001', 'DOC-000002'], $numbers);
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('cashBankModules')]
    public function test_status_filter_supports_single_and_comma_separated(string $uri, string $model, string $numberColumn, string $dateColumn, bool $acceptsAccountFilter): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn);

        $this->getJson("{$uri}?page=1&per_page=25&status=posted", $headers)
            ->assertJsonPath('data.total', 2);

        $this->getJson("{$uri}?page=1&per_page=25&status=draft,posted", $headers)
            ->assertJsonPath('data.total', 3);
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('cashBankModules')]
    public function test_date_range_is_inclusive(string $uri, string $model, string $numberColumn, string $dateColumn, bool $acceptsAccountFilter): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn);

        $res = $this->getJson("{$uri}?page=1&per_page=25&date_from=2026-01-10&date_to=2026-02-15", $headers);
        $res->assertStatus(200);
        $numbers = collect($res->json('data.data'))->pluck($numberColumn)->sort()->values()->all();
        $this->assertSame(['DOC-000001', 'DOC-000002'], $numbers);
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('cashBankModules')]
    public function test_sort_default_and_allowlist(string $uri, string $model, string $numberColumn, string $dateColumn, bool $acceptsAccountFilter): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn);

        $default = $this->getJson("{$uri}?page=1&per_page=25", $headers);
        $this->assertSame(
            ['DOC-000003', 'DOC-000002', 'DOC-000001'],
            collect($default->json('data.data'))->pluck($numberColumn)->all(),
        );

        $asc = $this->getJson("{$uri}?page=1&per_page=25&sort_by={$dateColumn}&sort_direction=asc", $headers);
        $this->assertSame(
            ['DOC-000001', 'DOC-000002', 'DOC-000003'],
            collect($asc->json('data.data'))->pluck($numberColumn)->all(),
        );

        $bogus = $this->getJson("{$uri}?page=1&per_page=25&sort_by=cash_bank_account_id;drop", $headers);
        $bogus->assertStatus(200);
        $this->assertSame(
            ['DOC-000003', 'DOC-000002', 'DOC-000001'],
            collect($bogus->json('data.data'))->pluck($numberColumn)->all(),
        );
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('cashBankModules')]
    public function test_pagination_shape_is_unchanged(string $uri, string $model, string $numberColumn, string $dateColumn, bool $acceptsAccountFilter): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn);

        $page1 = $this->getJson("{$uri}?page=1&per_page=2", $headers);
        $page1->assertStatus(200);
        $page1->assertJsonPath('data.total', 3);
        $page1->assertJsonPath('data.current_page', 1);
        $page1->assertJsonPath('data.per_page', 2);
        $page1->assertJsonPath('data.last_page', 2);
        $page1->assertJsonPath('data.from', 1);
        $page1->assertJsonPath('data.to', 2);
        $this->assertCount(2, $page1->json('data.data'));

        $empty = $this->getJson("{$uri}?page=999&per_page=25", $headers);
        $empty->assertJsonPath('data.total', 3);
        $empty->assertJsonPath('data.from', null);
        $empty->assertJsonPath('data.to', null);
        $this->assertCount(0, $empty->json('data.data'));
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('cashBankModules')]
    public function test_without_page_params_returns_unpaginated_list(string $uri, string $model, string $numberColumn, string $dateColumn, bool $acceptsAccountFilter): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn);

        $res = $this->getJson($uri, $headers);
        $res->assertStatus(200);
        $this->assertCount(3, $res->json('data'));
        $this->assertSame('DOC-000003', $res->json("data.0.{$numberColumn}"));
    }
}
