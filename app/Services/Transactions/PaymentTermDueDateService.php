<?php

namespace App\Services\Transactions;

use App\Models\Tenant\Contact;
use App\Models\Tenant\PaymentTerm;
use App\Services\Settings\CompanySettingService;
use App\Services\Tenant\TenantContext;
use Carbon\CarbonImmutable;

class PaymentTermDueDateService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CompanySettingService $companySettingService,
    ) {
    }

    public function apply(array $data, string $dateField, ?int $partnerId): array
    {
        if (empty($data[$dateField])) {
            return $data;
        }

        $paymentTerm = $this->resolve($data['payment_term_id'] ?? null, $partnerId);
        if (! $paymentTerm) {
            return $data;
        }

        if (empty($data['payment_term_id'])) {
            $data['payment_term_id'] = $paymentTerm->id;
        }

        if (empty($data['due_date']) && $paymentTerm->days !== null) {
            $data['due_date'] = CarbonImmutable::parse((string) $data[$dateField])
                ->addDays((int) $paymentTerm->days)
                ->toDateString();
        }

        return $data;
    }

    private function resolve(mixed $explicitPaymentTermId, ?int $partnerId): ?PaymentTerm
    {
        if ($explicitPaymentTermId !== null && $explicitPaymentTermId !== '') {
            return PaymentTerm::query()->find((int) $explicitPaymentTermId);
        }

        if ($partnerId) {
            $contactPaymentTermId = Contact::query()->whereKey($partnerId)->value('payment_term_id');
            if ($contactPaymentTermId) {
                $contactTerm = PaymentTerm::query()->find((int) $contactPaymentTermId);
                if ($contactTerm) {
                    return $contactTerm;
                }
            }
        }

        $company = $this->tenantContext->company();
        if ($company) {
            $companyTermId = $this->companySettingService
                ->getOrCreateAccountingSetting($company)
                ->default_payment_term_id;
            if ($companyTermId) {
                $companyTerm = PaymentTerm::query()->find((int) $companyTermId);
                if ($companyTerm) {
                    return $companyTerm;
                }
            }
        }

        return PaymentTerm::query()->where('code', 'NET_7')->first();
    }
}
