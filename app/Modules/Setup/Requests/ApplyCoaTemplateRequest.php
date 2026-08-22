<?php

namespace App\Modules\Setup\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyCoaTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'template_id' => ['required', 'string', 'max:80'],
            'accounts' => ['present', 'array'],
            'accounts.*.code' => ['required', 'string', 'max:50'],
            'accounts.*.name' => ['required', 'string', 'max:255'],
            'accounts.*.type' => ['required', 'in:asset,liability,equity,revenue,expense'],
            'accounts.*.parent_code' => ['nullable', 'string', 'max:50'],
            'accounts.*.is_cash_bank' => ['nullable', 'boolean'],
            'accounts.*.description' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $accounts = (array) $this->input('accounts', []);
            $codes = [];
            $seen = [];

            foreach ($accounts as $row) {
                $code = (string) ($row['code'] ?? '');
                if ($code === '') {
                    continue;
                }
                if (isset($seen[$code])) {
                    $validator->errors()->add('accounts', "Account code [{$code}] is duplicated in the payload.");
                }
                $seen[$code] = true;
                $codes[] = $code;
            }

            foreach ($accounts as $index => $row) {
                $parentCode = $row['parent_code'] ?? null;
                if ($parentCode !== null && ! in_array((string) $parentCode, $codes, true)) {
                    $validator->errors()->add(
                        "accounts.{$index}.parent_code",
                        "Parent account code [{$parentCode}] does not exist in the payload.",
                    );
                }
            }
        });
    }
}
