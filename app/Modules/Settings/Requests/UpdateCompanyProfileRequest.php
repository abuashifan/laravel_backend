<?php

namespace App\Modules\Settings\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Profil perusahaan yang boleh diubah pemilik: NPWP, alamat, dan kontak.
 * Nama, nama legal, dan kode sengaja tidak termasuk -- slug, kode, dan berkas
 * database tenant diturunkan dari nama saat perusahaan dibuat.
 */
class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tax_number' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
        ];
    }

    public function attributes(): array
    {
        return [
            'tax_number' => 'NPWP',
            'address' => 'alamat',
            'city' => 'kota',
            'province' => 'provinsi',
            'postal_code' => 'kode pos',
            'phone' => 'telepon',
            'email' => 'email',
        ];
    }
}
