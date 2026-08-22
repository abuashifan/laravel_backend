<?php

namespace App\Modules\Imports\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'profile' => ['required', 'string', Rule::in(array_keys((array) config('imports.profiles', [])))],
            // `mimetypes` lebih longgar dari `mimes` — browser bisa mengirim CSV
            // sebagai text/plain, application/octet-stream, atau application/vnd.ms-excel
            // tergantung OS dan asosiasi file. `application/octet-stream` sendiri
            // adalah fallback generik yang cocok untuk hampir semua berkas biner,
            // jadi ekstensi WAJIB dicek juga supaya whitelist mimetypes tidak
            // dilewati begitu saja oleh berkas non-CSV/XLSX. Validasi isi akan
            // tetap dilakukan pembaca spreadsheet saat inspeksi header/baris.
            'file' => [
                'required',
                'file',
                'max:10240',
                Rule::file()->extensions(['csv', 'txt', 'xlsx']),
                'mimetypes:text/csv,text/plain,application/csv,text/x-csv,application/x-csv,text/comma-separated-values,application/vnd.ms-excel,application/octet-stream,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            'confirm_duplicate_file' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'profile.required' => 'Profil impor wajib dipilih.',
            'profile.in' => 'Profil impor tidak dikenal.',
            'file.required' => 'Berkas wajib diunggah.',
            'file.file' => 'Berkas tidak valid.',
            'file.max' => 'Ukuran berkas maksimal 10 MB.',
            'file.extensions' => 'Format berkas harus .csv, .txt, atau .xlsx.',
            'file.mimetypes' => 'Format berkas harus .csv, .txt, atau .xlsx.',
        ];
    }
}
