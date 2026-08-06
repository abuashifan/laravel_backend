<?php

namespace Tests\Feature\Sales;

use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\Sales\Models\CustomerDeposit;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\ProformaInvoice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Models\SalesReceipt;
use App\Modules\Sales\Models\SalesReturn;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Menguji 8 endpoint daftar Sales setelah service-nya pindah dari in-memory
 * (`->get()` lalu disaring `listResponse`) ke SQL lewat `AppliesListQuery`
 * (Fase 2 / list-query-pushdown).
 *
 * Kedelapan modul dites lewat satu data provider karena bentuknya identik --
 * kalau ada satu service yang lupa mendeklarasikan salah satu dari enam
 * properti trait, errornya baru muncul saat runtime ("Typed property must not
 * be accessed before initialization"), jadi tiap endpoint harus benar-benar
 * dipanggil, bukan cuma satu sebagai perwakilan.
 *
 * Dua perubahan perilaku yang dikunci di sini:
 * 1. Pencarian nama customer mulai bekerja (`whereHas`). Sebelumnya
 *    `applyListSearch` hanya mencocokkan nilai `is_scalar()`, sedangkan relasi
 *    ter-eager-load muncul sebagai array bersarang -- jadi placeholder
 *    "Cari nomor invoice, customer..." tidak pernah ditepati.
 * 2. `customer_id` mulai difilter di 7 dari 8 service. Hanya
 *    SalesQuotationService yang memakainya sebelum fase ini, padahal
 *    kedelapan halaman frontend mengirimkannya -- dropdown filter Customer
 *    diam-diam tidak berpengaruh.
 */
class SalesListQueryTest extends SalesTestCase
{
    /**
     * @return array<string,array{0:string,1:class-string,2:string,3:string,4:array<string,mixed>}>
     */
    public static function salesModules(): array
    {
        // [uri, model, kolom nomor, kolom tanggal, kolom wajib tambahan]
        return [
            'quotations' => ['/api/sales/quotations', SalesQuotation::class, 'quotation_number', 'quotation_date', []],
            'orders' => ['/api/sales/orders', SalesOrder::class, 'order_number', 'order_date', []],
            'proformas' => ['/api/sales/proformas', ProformaInvoice::class, 'proforma_number', 'proforma_date', []],
            'delivery-orders' => ['/api/sales/delivery-orders', DeliveryOrder::class, 'delivery_number', 'delivery_date', []],
            'invoices' => ['/api/sales/invoices', SalesInvoice::class, 'invoice_number', 'invoice_date', []],
            'returns' => ['/api/sales/returns', SalesReturn::class, 'return_number', 'return_date', []],
            'customer-deposits' => ['/api/sales/customer-deposits', CustomerDeposit::class, 'deposit_number', 'deposit_date', []],
            'receipts' => ['/api/sales/receipts', SalesReceipt::class, 'receipt_number', 'receipt_date', ['amount' => 100]],
        ];
    }

    /**
     * Tiga dokumen: dua milik "Toko Melati" (draft + posted), satu milik
     * "Warung Kenanga" (posted), dengan tanggal berjenjang supaya filter
     * periode dan sort punya sesuatu untuk dibedakan.
     *
     * @param  class-string  $model
     * @param  array<string,mixed>  $extra
     * @return array{headers: array<string,string>, melati: int, kenanga: int}
     */
    private function seedDocuments(string $model, string $numberColumn, string $dateColumn, array $extra): array
    {
        $ctx = $this->setUpTenant();

        $melati = $this->createCustomer(['name' => 'Toko Melati', 'contact_code' => 'CUST-MELATI']);
        $kenanga = $this->createCustomer(['name' => 'Warung Kenanga', 'contact_code' => 'CUST-KENANGA']);

        if ($model === SalesReceipt::class) {
            $extra['cash_bank_account_id'] = ChartOfAccount::query()->create([
                'account_code' => '1-1100',
                'account_name' => 'Kas',
                'account_type' => 'asset',
                'normal_balance' => 'debit',
            ])->id;
        }

        $rows = [
            ['DOC-000001', '2026-01-10', 'draft', $melati],
            ['DOC-000002', '2026-02-15', 'posted', $melati],
            ['DOC-000003', '2026-03-20', 'posted', $kenanga],
        ];
        foreach ($rows as [$number, $date, $status, $customerId]) {
            $model::query()->create(array_merge($extra, [
                $numberColumn => $number,
                $dateColumn => $date,
                'status' => $status,
                'customer_id' => $customerId,
            ]));
        }

        return ['headers' => $ctx['headers'], 'melati' => $melati, 'kenanga' => $kenanga];
    }

    /**
     * @param  class-string  $model
     * @param  array<string,mixed>  $extra
     */
    #[DataProvider('salesModules')]
    public function test_search_matches_document_number(string $uri, string $model, string $numberColumn, string $dateColumn, array $extra): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $extra);

        $res = $this->getJson("{$uri}?page=1&per_page=25&search=000002", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath("data.data.0.{$numberColumn}", 'DOC-000002');
    }

    /**
     * Sebelum Fase 2 ini selalu 0 hasil -- relasi tidak pernah dicocokkan.
     *
     * @param  class-string  $model
     * @param  array<string,mixed>  $extra
     */
    #[DataProvider('salesModules')]
    public function test_search_matches_customer_name_through_relation(string $uri, string $model, string $numberColumn, string $dateColumn, array $extra): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $extra);

        $res = $this->getJson("{$uri}?page=1&per_page=25&search=Kenanga", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath("data.data.0.{$numberColumn}", 'DOC-000003');

        $byCode = $this->getJson("{$uri}?page=1&per_page=25&search=CUST-MELATI", $headers);
        $byCode->assertJsonPath('data.total', 2);
    }

    /**
     * Search + filter lain harus AND, bukan OR yang bocor: OR di dalam
     * pencarian dibungkus satu closure di `applyListSearchQuery()`.
     *
     * @param  class-string  $model
     * @param  array<string,mixed>  $extra
     */
    #[DataProvider('salesModules')]
    public function test_search_does_not_leak_past_status_filter(string $uri, string $model, string $numberColumn, string $dateColumn, array $extra): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $extra);

        // "DOC-" cocok ketiganya, tapi status=draft menyisakan satu.
        $res = $this->getJson("{$uri}?page=1&per_page=25&search=DOC-&status=draft", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath("data.data.0.{$numberColumn}", 'DOC-000001');
    }

    /**
     * Bug yang diperbaiki Fase 2: 7 dari 8 service mengabaikan `customer_id`.
     *
     * @param  class-string  $model
     * @param  array<string,mixed>  $extra
     */
    #[DataProvider('salesModules')]
    public function test_customer_id_filter_applies(string $uri, string $model, string $numberColumn, string $dateColumn, array $extra): void
    {
        ['headers' => $headers, 'melati' => $melati] = $this->seedDocuments($model, $numberColumn, $dateColumn, $extra);

        $res = $this->getJson("{$uri}?page=1&per_page=25&customer_id={$melati}", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 2);
        $numbers = collect($res->json('data.data'))->pluck($numberColumn)->sort()->values()->all();
        $this->assertSame(['DOC-000001', 'DOC-000002'], $numbers);
    }

    /**
     * @param  class-string  $model
     * @param  array<string,mixed>  $extra
     */
    #[DataProvider('salesModules')]
    public function test_status_filter_supports_single_and_comma_separated(string $uri, string $model, string $numberColumn, string $dateColumn, array $extra): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $extra);

        $this->getJson("{$uri}?page=1&per_page=25&status=posted", $headers)
            ->assertJsonPath('data.total', 2);

        // Sebelum fase ini where('status', 'draft,posted') literal -> selalu 0.
        $this->getJson("{$uri}?page=1&per_page=25&status=draft,posted", $headers)
            ->assertJsonPath('data.total', 3);
    }

    /**
     * @param  class-string  $model
     * @param  array<string,mixed>  $extra
     */
    #[DataProvider('salesModules')]
    public function test_date_range_is_inclusive(string $uri, string $model, string $numberColumn, string $dateColumn, array $extra): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $extra);

        $res = $this->getJson("{$uri}?page=1&per_page=25&date_from=2026-01-10&date_to=2026-02-15", $headers);
        $res->assertStatus(200);
        $numbers = collect($res->json('data.data'))->pluck($numberColumn)->sort()->values()->all();
        $this->assertSame(['DOC-000001', 'DOC-000002'], $numbers);
    }

    /**
     * Default: tanggal dokumen desc. `sort_by` di luar `$listSortable` harus
     * diabaikan (bukan dimasukkan mentah ke `orderBy()` -- itu celah injeksi).
     *
     * @param  class-string  $model
     * @param  array<string,mixed>  $extra
     */
    #[DataProvider('salesModules')]
    public function test_sort_default_and_allowlist(string $uri, string $model, string $numberColumn, string $dateColumn, array $extra): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $extra);

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

        $bogus = $this->getJson("{$uri}?page=1&per_page=25&sort_by=customer_id;drop", $headers);
        $bogus->assertStatus(200);
        $this->assertSame(
            ['DOC-000003', 'DOC-000002', 'DOC-000001'],
            collect($bogus->json('data.data'))->pluck($numberColumn)->all(),
        );
    }

    /**
     * @param  class-string  $model
     * @param  array<string,mixed>  $extra
     */
    #[DataProvider('salesModules')]
    public function test_pagination_shape_is_unchanged(string $uri, string $model, string $numberColumn, string $dateColumn, array $extra): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $extra);

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
     * Kontrak `listResponse`: tanpa `page`/`per_page` kirim semua tanpa
     * paginasi. Dilanggar di draft awal Fase 1 dan hanya ketahuan lewat test.
     *
     * @param  class-string  $model
     * @param  array<string,mixed>  $extra
     */
    #[DataProvider('salesModules')]
    public function test_without_page_params_returns_unpaginated_list(string $uri, string $model, string $numberColumn, string $dateColumn, array $extra): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn, $extra);

        $res = $this->getJson($uri, $headers);
        $res->assertStatus(200);
        $this->assertCount(3, $res->json('data'));
        $this->assertSame('DOC-000003', $res->json("data.0.{$numberColumn}"));
    }
}
