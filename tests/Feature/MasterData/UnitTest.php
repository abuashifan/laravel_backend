<?php

namespace Tests\Feature\MasterData;

class UnitTest extends MasterDataTestCase
{
    public function test_create_update_deactivate_unit_and_duplicate_code_rejected(): void
    {
        $ctx = $this->setUpTenant();

        $unit = $this->postJson('/api/master-data/units', [
            'code' => 'PCS',
            'name' => 'Pieces',
            'precision' => 0,
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->postJson('/api/master-data/units', [
            'code' => 'PCS',
            'name' => 'Pieces 2',
            'precision' => 0,
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code'])
            ->assertDontSee('SQLSTATE');

        $this->patchJson('/api/master-data/units/'.$unit['id'], [
            'name' => 'PCS Updated',
        ], $ctx['headers'])->assertStatus(200)
            ->assertJsonPath('data.name', 'PCS Updated');

        $this->patchJson('/api/master-data/units/'.$unit['id'].'/deactivate', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_create_unit_requires_precision_before_database_insert(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/master-data/units', [
            'code' => 'BOX',
            'name' => 'Box',
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Please review the highlighted fields.')
            ->assertJsonValidationErrors(['precision'])
            ->assertJsonPath('errors.precision.0', 'Precision is required.')
            ->assertDontSee('SQLSTATE');
    }
}
