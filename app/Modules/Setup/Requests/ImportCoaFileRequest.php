<?php

namespace App\Modules\Setup\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportCoaFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Sama dengan `StoreImportRequest` -- `mimetypes` dilonggarkan karena
            // browser mengirim CSV dengan MIME yang beda-beda tergantung OS.
            'file' => [
                'required',
                'file',
                'max:10240',
                Rule::file()->extensions(['csv', 'txt', 'xlsx']),
                'mimetypes:text/csv,text/plain,application/csv,text/x-csv,application/x-csv,text/comma-separated-values,application/vnd.ms-excel,application/octet-stream,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Berkas wajib diunggah.',
            'file.file' => 'Berkas tidak valid.',
            'file.max' => 'Ukuran berkas maksimal 10 MB.',
            'file.extensions' => 'Format berkas harus .csv, .txt, atau .xlsx.',
            'file.mimetypes' => 'Format berkas harus .csv, .txt, atau .xlsx.',
        ];
    }
}
