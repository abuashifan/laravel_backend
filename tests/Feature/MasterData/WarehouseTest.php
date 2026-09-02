<?php

namespace Tests\Feature\MasterData;

class WarehouseTest extends MasterDataTestCase
{
    public function test_create_warehouse_code_unique_set_default_and_deactivate(): void
    {
        $ctx = $this->setUpTenant();

        $w1 = $this->postJson('/api/master-data/warehouses', [
            'code' => 'WH-01',
            'name' => 'Main',
            'is_default' => true,
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->postJson('/api/master-data/warehouses', [
            'code' => 'WH-01',
            'name' => 'Dup',
        ], $ctx['headers'])->assertStatus(422);

        $w2 = $this->postJson('/api/master-data/warehouses', [
            'code' => 'WH-02',
            'name' => 'Secondary',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/master-data/warehouses/'.$w2['id'], [
            'is_default' => true,
        ], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.is_default', true);

        // old default should be unset
        $this->getJson('/api/master-data/warehouses/'.$w1['id'], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.is_default', false);

        // cannot deactivate default
        $this->patchJson('/api/master-data/warehouses/'.$w2['id'].'/deactivate', [], $ctx['headers'])->assertStatus(422);
    }

    public function test_warehouse_name_must_be_unique_ignoring_case_and_spacing(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/master-data/warehouses', [
            'code' => 'WH-01',
            'name' => 'Gudang Utama',
        ], $ctx['headers'])->assertStatus(201);

        // Nama persis sama.
        $this->postJson('/api/master-data/warehouses', [
            'code' => 'WH-02',
            'name' => 'Gudang Utama',
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_WAREHOUSE_NAME')
            ->assertJsonPath('errors.name.0', 'Name is already in use.');

        // Beda huruf besar/kecil dan spasi di ujung tetap dianggap kembar.
        $this->postJson('/api/master-data/warehouses', [
            'code' => 'WH-03',
            'name' => '  gudang utama ',
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_WAREHOUSE_NAME');

        // Nama lain tetap boleh.
        $this->postJson('/api/master-data/warehouses', [
            'code' => 'WH-04',
            'name' => 'Gudang Cabang',
        ], $ctx['headers'])->assertStatus(201);
    }

    public function test_update_rejects_name_of_another_warehouse_but_allows_own_name(): void
    {
        $ctx = $this->setUpTenant();

        $w1 = $this->postJson('/api/master-data/warehouses', [
            'code' => 'WH-01',
            'name' => 'Gudang Utama',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $w2 = $this->postJson('/api/master-data/warehouses', [
            'code' => 'WH-02',
            'name' => 'Gudang Cabang',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/master-data/warehouses/'.$w2['id'], [
            'name' => 'gudang utama',
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_WAREHOUSE_NAME');

        // Menyimpan ulang nama sendiri (termasuk hanya ganti kapitalisasi) bukan duplikat.
        $this->patchJson('/api/master-data/warehouses/'.$w1['id'], [
            'name' => 'GUDANG UTAMA',
            'address' => 'Jl. Baru 1',
        ], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'GUDANG UTAMA');
    }
}
