<?php

namespace App\Shared\TransactionLifecycle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Hapus dokumen transaksi. Alasan opsional untuk draft (dihapus permanen) dan
 * dipakai sebagai alasan void saat transaksi yang sudah berefek dihapus.
 */
class DeleteTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Alasan hapus minimal 3 karakter.',
            'reason.max' => 'Alasan hapus maksimal 1000 karakter.',
        ];
    }
}
