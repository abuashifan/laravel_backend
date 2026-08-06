<?php

namespace Tests\Feature\MasterData;

/**
 * Menguji `App\Shared\Api\ResolvesAdjacentRecords` lewat endpoint produk.
 *
 * Trait ini dipakai 27 endpoint dengan pemanggilan identik (hanya model dan
 * kolom label yang berbeda), jadi perilakunya cukup dikunci di satu tempat.
 */
class AdjacentRecordsTest extends MasterDataTestCase
{
    /**
     * @return array{headers: array<string,string>, ids: array<int,int>, codes: array<int,string>}
     */
    private function seedProducts(): array
    {
        $ctx = $this->setUpTenant();

        $ids = [];
        $codes = [];
        foreach (['Produk Satu', 'Produk Dua', 'Produk Tiga'] as $index => $name) {
            // `product_code` diisi eksplisit: kolom ini opsional di backend, dan
            // di sinilah nilai yang dipakai sebagai label tab berasal.
            $code = 'PRD-'.($index + 1);
            $created = $this->postJson('/api/master-data/products', [
                'product_code' => $code,
                'product_name' => $name,
                'product_type' => 'service',
                'is_stock_item' => false,
            ], $ctx['headers'])->assertStatus(201)->json('data');

            $ids[] = (int) $created['id'];
            $codes[] = $code;
        }

        return ['headers' => $ctx['headers'], 'ids' => $ids, 'codes' => $codes];
    }

    public function test_returns_both_neighbours_for_a_middle_record(): void
    {
        ['headers' => $headers, 'ids' => $ids, 'codes' => $codes] = $this->seedProducts();

        $this->getJson('/api/master-data/products/adjacent?id='.$ids[1], $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.prev.id', $ids[0])
            ->assertJsonPath('data.prev.label', $codes[0])
            ->assertJsonPath('data.next.id', $ids[2])
            ->assertJsonPath('data.next.label', $codes[2]);
    }

    public function test_first_record_has_no_previous_and_last_has_no_next(): void
    {
        ['headers' => $headers, 'ids' => $ids] = $this->seedProducts();

        $this->getJson('/api/master-data/products/adjacent?id='.$ids[0], $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.prev', null)
            ->assertJsonPath('data.next.id', $ids[1]);

        $this->getJson('/api/master-data/products/adjacent?id='.$ids[2], $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.prev.id', $ids[1])
            ->assertJsonPath('data.next', null);
    }

    /** Form create dianggap berada sesudah record terakhir. */
    public function test_without_id_points_at_the_latest_record_and_has_no_next(): void
    {
        ['headers' => $headers, 'ids' => $ids, 'codes' => $codes] = $this->seedProducts();

        $this->getJson('/api/master-data/products/adjacent', $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.prev.id', $ids[2])
            ->assertJsonPath('data.prev.label', $codes[2])
            ->assertJsonPath('data.next', null);
    }

    /** Hanya id dan label yang dikirim — inilah alasan endpoint ini ada. */
    public function test_payload_carries_only_id_and_label(): void
    {
        ['headers' => $headers, 'ids' => $ids] = $this->seedProducts();

        $prev = $this->getJson('/api/master-data/products/adjacent?id='.$ids[1], $headers)
            ->assertStatus(200)
            ->json('data.prev');

        $this->assertSame(['id', 'label'], array_keys($prev));
    }

    public function test_tenant_without_records_gets_null_on_both_sides(): void
    {
        $ctx = $this->setUpTenant();

        $this->getJson('/api/master-data/products/adjacent', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.prev', null)
            ->assertJsonPath('data.next', null);
    }

    /**
     * Endpoint ini dijaga permission yang sama dengan daftarnya (`products.view`),
     * bukan permission baru — supaya tidak ada celah baca lewat jalur navigasi.
     */
    public function test_is_gated_by_the_same_permission_as_the_list(): void
    {
        $ctx = $this->setUpTenant('viewer');

        $this->getJson('/api/master-data/products', $ctx['headers'])->assertStatus(403);
        $this->getJson('/api/master-data/products/adjacent', $ctx['headers'])->assertStatus(403);
    }
}
