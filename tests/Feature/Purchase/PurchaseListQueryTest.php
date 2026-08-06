<?php

namespace Tests\Feature\Purchase;

use App\Modules\Purchase\Models\GoodsReceipt;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseRequest;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Models\VendorBill;
use App\Modules\Purchase\Models\VendorDeposit;
use App\Modules\Purchase\Models\VendorPayment;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Menguji 7 endpoint daftar Purchase setelah service-nya pindah ke SQL lewat
 * `AppliesListQuery` (Fase 3 / list-query-pushdown). Kembaran dari
 * `Tests\Feature\Sales\SalesListQueryTest`; alasan memakai data provider sama
 * -- properti trait yang lupa dideklarasikan baru meledak saat runtime, jadi
 * tiap endpoint harus benar-benar dipanggil.
 *
 * PurchaseRequest berbeda dari enam lainnya: tabelnya tidak punya `vendor_id`
 * sama sekali (dokumen internal, vendornya baru ditentukan di PO), jadi tidak
 * ikut dites untuk pencarian relasi maupun filter vendor.
 */
class PurchaseListQueryTest extends PurchaseTestCase
{
    /**
     * @return array<string,array{0:string,1:class-string,2:string,3:string,4:bool}>
     */
    public static function purchaseModules(): array
    {
        // [uri, model, kolom nomor, kolom tanggal, punya relasi vendor]
        return [
            'requests' => ['/api/purchase/requests', PurchaseRequest::class, 'request_number', 'request_date', false],
            'orders' => ['/api/purchase/orders', PurchaseOrder::class, 'order_number', 'order_date', true],
            'goods-receipts' => ['/api/purchase/goods-receipts', GoodsReceipt::class, 'receipt_number', 'receipt_date', true],
            'bills' => ['/api/purchase/bills', VendorBill::class, 'bill_number', 'bill_date', true],
            'returns' => ['/api/purchase/returns', PurchaseReturn::class, 'return_number', 'return_date', true],
            'vendor-deposits' => ['/api/purchase/vendor-deposits', VendorDeposit::class, 'deposit_number', 'deposit_date', true],
            'payments' => ['/api/purchase/payments', VendorPayment::class, 'payment_number', 'payment_date', true],
        ];
    }

    /**
     * @param  class-string  $model
     * @return array{headers: array<string,string>, jaya: int, sentosa: int}
     */
    private function seedDocuments(string $model, string $numberColumn, string $dateColumn): array
    {
        $ctx = $this->setUpTenant();

        $jaya = $this->createVendor(['name' => 'PT Jaya Abadi', 'contact_code' => 'VEND-JAYA']);
        $sentosa = $this->createVendor(['name' => 'CV Sentosa Makmur', 'contact_code' => 'VEND-SENTOSA']);

        $extra = [];
        if ($model === VendorPayment::class) {
            $extra['cash_bank_account_id'] = $this->createAccount('asset', '1-1100', true);
            $extra['amount'] = 100;
        }

        $rows = [
            ['DOC-000001', '2026-01-10', 'draft', $jaya],
            ['DOC-000002', '2026-02-15', 'posted', $jaya],
            ['DOC-000003', '2026-03-20', 'posted', $sentosa],
        ];
        foreach ($rows as [$number, $date, $status, $vendorId]) {
            $attributes = array_merge($extra, [
                $numberColumn => $number,
                $dateColumn => $date,
                'status' => $status,
            ]);
            // purchase_requests tidak punya kolom vendor_id.
            if ($model !== PurchaseRequest::class) {
                $attributes['vendor_id'] = $vendorId;
            }
            $model::query()->create($attributes);
        }

        return ['headers' => $ctx['headers'], 'jaya' => $jaya, 'sentosa' => $sentosa];
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('purchaseModules')]
    public function test_search_matches_document_number(string $uri, string $model, string $numberColumn, string $dateColumn, bool $hasVendor): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn);

        $res = $this->getJson("{$uri}?page=1&per_page=25&search=000002", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath("data.data.0.{$numberColumn}", 'DOC-000002');
    }

    /**
     * Sebelum Fase 3 ini selalu 0 hasil -- relasi tidak pernah dicocokkan.
     *
     * @param  class-string  $model
     */
    #[DataProvider('purchaseModules')]
    public function test_search_matches_vendor_name_through_relation(string $uri, string $model, string $numberColumn, string $dateColumn, bool $hasVendor): void
    {
        if (! $hasVendor) {
            $this->markTestSkipped('purchase_requests tidak punya relasi vendor.');
        }

        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn);

        $res = $this->getJson("{$uri}?page=1&per_page=25&search=Sentosa", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath("data.data.0.{$numberColumn}", 'DOC-000003');

        $byCode = $this->getJson("{$uri}?page=1&per_page=25&search=VEND-JAYA", $headers);
        $byCode->assertJsonPath('data.total', 2);
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('purchaseModules')]
    public function test_search_does_not_leak_past_status_filter(string $uri, string $model, string $numberColumn, string $dateColumn, bool $hasVendor): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn);

        $res = $this->getJson("{$uri}?page=1&per_page=25&search=DOC-&status=draft", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 1);
        $res->assertJsonPath("data.data.0.{$numberColumn}", 'DOC-000001');
    }

    /**
     * `PurchaseReturnService` dan `VendorPaymentService` mengabaikan
     * `vendor_id` sebelum Fase 3; lima lainnya sudah memakainya.
     *
     * @param  class-string  $model
     */
    #[DataProvider('purchaseModules')]
    public function test_vendor_id_filter_applies(string $uri, string $model, string $numberColumn, string $dateColumn, bool $hasVendor): void
    {
        if (! $hasVendor) {
            $this->markTestSkipped('purchase_requests tidak punya kolom vendor_id.');
        }

        ['headers' => $headers, 'jaya' => $jaya] = $this->seedDocuments($model, $numberColumn, $dateColumn);

        $res = $this->getJson("{$uri}?page=1&per_page=25&vendor_id={$jaya}", $headers);
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 2);
        $numbers = collect($res->json('data.data'))->pluck($numberColumn)->sort()->values()->all();
        $this->assertSame(['DOC-000001', 'DOC-000002'], $numbers);
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('purchaseModules')]
    public function test_status_filter_supports_single_and_comma_separated(string $uri, string $model, string $numberColumn, string $dateColumn, bool $hasVendor): void
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
    #[DataProvider('purchaseModules')]
    public function test_date_range_is_inclusive(string $uri, string $model, string $numberColumn, string $dateColumn, bool $hasVendor): void
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
    #[DataProvider('purchaseModules')]
    public function test_sort_default_and_allowlist(string $uri, string $model, string $numberColumn, string $dateColumn, bool $hasVendor): void
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

        $bogus = $this->getJson("{$uri}?page=1&per_page=25&sort_by=vendor_id;drop", $headers);
        $bogus->assertStatus(200);
        $this->assertSame(
            ['DOC-000003', 'DOC-000002', 'DOC-000001'],
            collect($bogus->json('data.data'))->pluck($numberColumn)->all(),
        );
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('purchaseModules')]
    public function test_pagination_shape_is_unchanged(string $uri, string $model, string $numberColumn, string $dateColumn, bool $hasVendor): void
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
    #[DataProvider('purchaseModules')]
    public function test_without_page_params_returns_unpaginated_list(string $uri, string $model, string $numberColumn, string $dateColumn, bool $hasVendor): void
    {
        ['headers' => $headers] = $this->seedDocuments($model, $numberColumn, $dateColumn);

        $res = $this->getJson($uri, $headers);
        $res->assertStatus(200);
        $this->assertCount(3, $res->json('data'));
        $this->assertSame('DOC-000003', $res->json("data.0.{$numberColumn}"));
    }
}
