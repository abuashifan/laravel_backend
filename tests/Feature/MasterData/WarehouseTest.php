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
}

