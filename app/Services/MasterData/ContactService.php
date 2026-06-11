<?php

namespace App\Services\MasterData;

use App\Exceptions\ApiException;
use App\Models\Tenant\Contact;

class ContactService
{
    public function list(array $filters = [])
    {
        $query = Contact::query();

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['contact_type'])) {
            $query->where('contact_type', (string) $filters['contact_type']);
        }

        return $query->orderBy('name')->get();
    }

    public function create(array $data): Contact
    {
        $data = $this->withoutDeprecatedAccountingFields($data);

        if (! empty($data['contact_code']) && Contact::query()->where('contact_code', (string) $data['contact_code'])->exists()) {
            throw ApiException::make('DUPLICATE_CONTACT_CODE', 'Contact code is already in use.', 422, [
                'contact_code' => ['Contact Code is already in use.'],
            ]);
        }

        return Contact::query()->create($data);
    }

    public function update(Contact $contact, array $data): Contact
    {
        $data = $this->withoutDeprecatedAccountingFields($data);

        if (! empty($data['contact_code']) && $data['contact_code'] !== $contact->contact_code) {
            if (Contact::query()->where('contact_code', (string) $data['contact_code'])->exists()) {
                throw ApiException::make('DUPLICATE_CONTACT_CODE', 'Contact code is already in use.', 422, [
                    'contact_code' => ['Contact Code is already in use.'],
                ]);
            }
        }

        $contact->fill($data);
        $contact->save();

        return $contact->refresh();
    }

    public function deactivate(Contact $contact): Contact
    {
        $contact->is_active = false;
        $contact->save();

        return $contact->refresh();
    }

    public function activate(Contact $contact): Contact
    {
        $contact->is_active = true;
        $contact->save();

        return $contact->refresh();
    }

    private function withoutDeprecatedAccountingFields(array $data): array
    {
        unset(
            $data['receivable_account_id'],
            $data['payable_account_id'],
            $data['account_receivable_id'],
            $data['account_payable_id'],
        );

        return $data;
    }
}
