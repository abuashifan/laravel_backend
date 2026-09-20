<?php

namespace App\Modules\Imports\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RevertImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Alasan wajib, sama seperti void dokumen mana pun: pembatalan impor
     * menghapus atau membatalkan dokumen yang sudah masuk buku, dan riwayatnya
     * harus bisa menjawab "kenapa" berbulan-bulan kemudian.
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
