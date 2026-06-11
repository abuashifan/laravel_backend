<?php

namespace Tests\Feature\MasterData;

use App\Models\Tenant\ChartOfAccount;

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

    public function test_contact_can_store_and_update_account_fields(): void
    {
        $ctx = $this->setUpTenant();
        $ar = $this->account('1100', 'Piutang Usaha', 'asset', 'debit');
        $ap = $this->account('2100', 'Hutang Usaha', 'liability', 'credit');

        $contact = $this->postJson('/api/master-data/contacts', [
            'name' => 'Partner A',
            'contact_type' => 'customer',
            'receivable_account_id' => $ar,
            'payable_account_id' => $ap,
        ], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.receivable_account_id', $ar)
            ->assertJsonPath('data.payable_account_id', $ap)
            ->json('data');

        $nextAr = $this->account('1110', 'Piutang Corporate', 'asset', 'debit');

        $this->patchJson('/api/master-data/contacts/'.$contact['id'], [
            'receivable_account_id' => $nextAr,
            'payable_account_id' => null,
        ], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.receivable_account_id', $nextAr)
            ->assertJsonPath('data.payable_account_id', null);
    }

    private function account(string $code, string $name, string $type, string $normal): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $normal,
            'is_active' => true,
        ])->id;
    }
}
