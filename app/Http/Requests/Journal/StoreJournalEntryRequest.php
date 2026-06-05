<?php

namespace App\Http\Requests\Journal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'journal_date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.department_id' => ['nullable', 'integer'],
            'lines.*.project_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_order' => ['nullable', 'integer', 'min:0'],
            'lines.*.metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lines = (array) $this->input('lines', []);

            $totalDebit = 0.0;
            $totalCredit = 0.0;

            foreach (array_values($lines) as $i => $line) {
                $debit = (float) ($line['debit'] ?? 0);
                $credit = (float) ($line['credit'] ?? 0);

                if ($debit > 0 && $credit > 0) {
                    $validator->errors()->add("lines.$i", 'A line cannot have both debit and credit.');
                }

                if ($debit == 0.0 && $credit == 0.0) {
                    $validator->errors()->add("lines.$i", 'A line must have either debit or credit.');
                }

                $totalDebit += $debit;
                $totalCredit += $credit;
            }

            if (abs($totalDebit - $totalCredit) > 0.0001) {
                $validator->errors()->add('lines', 'Total debit must equal total credit.');
            }
        });
    }
}
