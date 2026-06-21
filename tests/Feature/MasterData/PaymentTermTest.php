<?php

namespace Tests\Feature\MasterData;

class PaymentTermTest extends MasterDataTestCase
{
    public function test_payment_term_validation_search_pagination_and_reactivation(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/master-data/payment-terms', [
            'code' => 'ZERO',
            'name' => 'Zero Days',
            'days' => 0,
        ], $ctx['headers'])->assertStatus(422);

        $net30 = $this->postJson('/api/master-data/payment-terms', [
            'code' => 'AUDNET30',
            'name' => 'Net 30',
            'days' => 30,
        ], $ctx['headers'])->assertCreated()->json('data');

        $cod = $this->postJson('/api/master-data/payment-terms', [
            'code' => 'AUDCOD',
            'name' => 'Cash on Delivery',
            'days' => 1,
        ], $ctx['headers'])->assertCreated()->json('data');

        $this->getJson('/api/master-data/payment-terms?page=1&per_page=25&search=AUDNET', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.code', 'AUDNET30');

        $this->patchJson('/api/master-data/payment-terms/'.$net30['id'].'/deactivate', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->patchJson('/api/master-data/payment-terms/'.$net30['id'].'/activate', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->patchJson('/api/master-data/payment-terms/'.$cod['id'].'/deactivate', [], $ctx['headers'])
            ->assertOk();

        $this->patchJson('/api/settings/company/transaction-defaults', [
            'default_payment_term_id' => $cod['id'],
        ], $ctx['headers'])->assertStatus(422);

        $this->patchJson('/api/settings/company/transaction-defaults', [
            'default_payment_term_id' => $net30['id'],
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.transaction_defaults.default_payment_term_id', $net30['id']);

        $this->patchJson('/api/master-data/payment-terms/'.$net30['id'].'/deactivate', [], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CANNOT_DEACTIVATE_DEFAULT_PAYMENT_TERM');

        $this->patchJson('/api/master-data/payment-terms/'.$net30['id'], [
            'is_active' => false,
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CANNOT_DEACTIVATE_DEFAULT_PAYMENT_TERM');
    }
}
