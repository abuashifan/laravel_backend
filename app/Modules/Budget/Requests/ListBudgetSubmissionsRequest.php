<?php

namespace App\Modules\Budget\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBudgetSubmissionsRequest extends FormRequest
{
    /** Status yang boleh dipakai filter. Cerminan enum kolom `budget_submissions.status`. */
    public const STATUSES = [
        'draft',
        'submitted',
        'approved_by_head',
        'approved',
        'rejected',
        'superseded',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'budget_period_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            // Trait AppliesListQuery menerima daftar dipisah koma, jadi divalidasi
            // per nilai lewat closure alih-alih Rule::in pada string utuh.
            'status' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                foreach (explode(',', (string) $value) as $status) {
                    $status = trim($status);
                    if ($status !== '' && ! in_array($status, self::STATUSES, true)) {
                        $fail("Status tidak dikenal: {$status}.");
                    }
                }
            }],
            // Bukan `boolean`: aturan itu menolak string "true"/"false", dan query
            // string tidak punya tipe boolean — `?is_active=false` selalu tiba
            // sebagai string. Service menormalkannya lewat filter_var.
            'is_active' => ['nullable', 'in:true,false,1,0'],
            'search' => ['nullable', 'string', 'max:255'],
            // Allowlist dipasang dua kali: di sini supaya pengguna dapat pesan yang
            // jelas, dan di `$listSortable` supaya nilai liar tetap diabaikan bila
            // service dipanggil dari jalur lain.
            'sort_by' => ['nullable', 'string', Rule::in(['created_at', 'version_no', 'status', 'budget_period_id', 'department_id'])],
            'sort_direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
