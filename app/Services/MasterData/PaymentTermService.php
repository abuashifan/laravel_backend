<?php

namespace App\Services\MasterData;

use App\Exceptions\ApiException;
use App\Models\Tenant\PaymentTerm;

class PaymentTermService
{
    public function list(array $filters = [])
    {
        $query = PaymentTerm::query();

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query->orderBy('sort_order')->orderBy('name')->get();
    }

    public function create(array $data): PaymentTerm
    {
        $code = strtoupper((string) $data['code']);
        if (PaymentTerm::query()->where('code', $code)->exists()) {
            throw ApiException::make('DUPLICATE_PAYMENT_TERM_CODE', 'Payment term code is already in use.', 422, [
                'code' => ['Code is already in use.'],
            ]);
        }

        $data['code'] = $code;
        $this->normalize($data);

        return PaymentTerm::query()->create($data);
    }

    public function update(PaymentTerm $paymentTerm, array $data): PaymentTerm
    {
        if (isset($data['code'])) {
            $data['code'] = strtoupper((string) $data['code']);
            if ($data['code'] !== $paymentTerm->code && PaymentTerm::query()->where('code', $data['code'])->exists()) {
                throw ApiException::make('DUPLICATE_PAYMENT_TERM_CODE', 'Payment term code is already in use.', 422, [
                    'code' => ['Code is already in use.'],
                ]);
            }
        }

        $this->normalize($data);
        $paymentTerm->fill($data);
        $paymentTerm->save();

        return $paymentTerm->refresh();
    }

    public function deactivate(PaymentTerm $paymentTerm): PaymentTerm
    {
        $paymentTerm->is_active = false;
        $paymentTerm->save();

        return $paymentTerm->refresh();
    }

    public function activate(PaymentTerm $paymentTerm): PaymentTerm
    {
        $paymentTerm->is_active = true;
        $paymentTerm->save();

        return $paymentTerm->refresh();
    }

    private function normalize(array &$data): void
    {
        if (($data['is_custom'] ?? false) === true) {
            $data['days'] = null;
        }
        if (! array_key_exists('is_active', $data)) {
            $data['is_active'] = true;
        }
        if (! array_key_exists('sort_order', $data)) {
            $data['sort_order'] = 0;
        }
    }
}
