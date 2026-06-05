<?php

namespace App\Http\Requests\Sales;

use Closure;

class StoreProformaInvoiceRequest extends StoreSalesQuotationRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['quotation_date'], $rules['quotation_for']);
        $rules['proforma_date'] = ['required', 'date_format:Y-m-d'];
        $rules['valid_until'] = ['nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail): void {
            $proformaDate = $this->input('proforma_date');
            if (is_string($proformaDate) && is_string($value) && $value < $proformaDate) {
                $fail('The valid until date must be a date after or equal to proforma date.');
            }
        }];
        $rules['sales_quotation_id'] = ['nullable', 'integer'];
        $rules['sales_order_id'] = ['nullable', 'integer'];
        $rules['source_type'] = ['nullable', 'in:sales_quotation,sales_order'];

        return $rules;
    }
}
