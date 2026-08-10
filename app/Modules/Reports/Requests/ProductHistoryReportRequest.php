<?php

namespace App\Modules\Reports\Requests;

use App\Modules\Reports\Requests\Concerns\HasReportDateFilters;
use App\Modules\Reports\Requests\Concerns\HasReportDimensionFilters;
use Illuminate\Foundation\Http\FormRequest;

class ProductHistoryReportRequest extends FormRequest
{
    use HasReportDateFilters;
    use HasReportDimensionFilters;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->dateFilterRules(),
            ...$this->dimensionFilterRules(),
            // `required`, bukan `nullable` seperti laporan per-barang lain:
            // tanpa produk, hasilnya jadi gabungan seluruh baris dokumen empat
            // tabel sekaligus -- itu bukan riwayat produk, dan biayanya tak
            // terbatas.
            'product_id' => ['required', 'integer', 'exists:tenant.products,id'],
        ];
    }
}
