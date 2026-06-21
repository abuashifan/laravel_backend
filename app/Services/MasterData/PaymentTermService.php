<?php

namespace App\Services\MasterData;

use App\Exceptions\ApiException;
use App\Models\CompanyAccountingSetting;
use App\Models\Tenant\PaymentTerm;
use App\Services\Tenant\TenantContext;

class PaymentTermService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function list(array $filters = [])
    {
        $query = PaymentTerm::query();

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace('%', '', (string) $filters['search']).'%';
            $query->where(function ($builder) use ($term): void {
                $builder
                    ->where('code', 'like', $term)
                    ->orWhere('name', 'like', $term);
            });
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

        if (array_key_exists('is_active', $data) && $data['is_active'] === false) {
            $this->assertCanDeactivate($paymentTerm);
        }

        $this->normalize($data);
        $paymentTerm->fill($data);
        $paymentTerm->save();

        return $paymentTerm->refresh();
    }

    public function deactivate(PaymentTerm $paymentTerm): PaymentTerm
    {
        $this->assertCanDeactivate($paymentTerm);

        $paymentTerm->is_active = false;
        $paymentTerm->save();

        return $paymentTerm->refresh();
    }

    private function assertCanDeactivate(PaymentTerm $paymentTerm): void
    {
        $companyId = $this->tenantContext->companyId();
        if ($companyId !== null && CompanyAccountingSetting::query()
            ->where('company_id', $companyId)
            ->where('default_payment_term_id', $paymentTerm->id)
            ->exists()) {
            throw ApiException::make(
                'CANNOT_DEACTIVATE_DEFAULT_PAYMENT_TERM',
                'Default payment term cannot be deactivated. Select another default first.',
                422
            );
        }
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
