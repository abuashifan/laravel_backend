<?php

namespace Tests\Feature\MasterData;

class ContactTest extends MasterDataTestCase
{
    public function test_create_customer_and_supplier_and_deactivate(): void
    {
        $ctx = $this->setUpTenant();

        $customer = $this->postJson('/api/master-data/contacts', [
            'name' => 'Customer A',
            'contact_type' => 'customer',
            'is_customer' => true,
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $supplier = $this->postJson('/api/master-data/contacts', [
            'name' => 'Supplier A',
            'contact_type' => 'supplier',
            'is_supplier' => true,
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $both = $this->postJson('/api/master-data/contacts', [
            'name' => 'Both A',
            'is_customer' => true,
            'is_supplier' => true,
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/master-data/contacts/'.$customer['id'], [
            'phone' => '081234',
        ], $ctx['headers'])->assertStatus(200);

        $this->patchJson('/api/master-data/contacts/'.$supplier['id'].'/deactivate', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_contact_index_supports_conditional_remote_pagination_search_and_sort(): void
    {
        $ctx = $this->setUpTenant();

        foreach (['Charlie Customer', 'Alpha Customer', 'Beta Supplier'] as $name) {
            $this->postJson('/api/master-data/contacts', [
                'name' => $name,
                'contact_type' => str_contains($name, 'Supplier') ? 'supplier' : 'customer',
            ], $ctx['headers'])->assertStatus(201);
        }

        $plain = $this->getJson('/api/master-data/contacts', $ctx['headers'])
            ->assertStatus(200);
        $this->assertIsArray($plain->json('data'));
        $this->assertArrayNotHasKey('current_page', $plain->json('data'));

        $page = $this->getJson('/api/master-data/contacts?page=1&per_page=2&search=Customer&sort_by=name&sort_direction=asc', $ctx['headers'])
            ->assertStatus(200);

        $page->assertJsonPath('data.current_page', 1);
        $page->assertJsonPath('data.per_page', 2);
        $page->assertJsonPath('data.total', 2);
        $page->assertJsonPath('data.last_page', 1);
        $this->assertSame(['Alpha Customer', 'Charlie Customer'], array_column($page->json('data.data'), 'name'));
    }
}
