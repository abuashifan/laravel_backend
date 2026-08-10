<?php

namespace App\Modules\Sales\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `SalesReturnService::normalizeLines()` sudah membaca product_id,
     * product_code, unit_id, warehouse_id, department_id, dan project_id --
     * tapi sebelumnya keenamnya tidak ada di sini, sehingga `validated()`
     * membuangnya sebelum sampai ke service.
     *
     * Akibatnya retur penjualan yang dibuat langsung lewat API tersimpan tanpa
     * produk: barisnya ada, `product_id` NULL. Retur yang dibuat dari faktur
     * tidak terkena karena `createFromInvoice()` memanggil `create()` langsung,
     * melewati FormRequest ini. Daftar field diselaraskan dengan
     * `StorePurchaseReturnRequest` yang memang sudah lengkap.
     */
    public function rules(): array
    {
        return ['return_date' => ['required', 'date'], 'customer_id' => ['required', 'exists:tenant.contacts,id'], 'sales_invoice_id' => ['nullable', 'integer'], 'delivery_order_id' => ['nullable', 'integer'], 'reason' => ['nullable', 'string'], 'notes' => ['nullable', 'string'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.sales_invoice_line_id' => ['nullable', 'integer'], 'lines.*.delivery_order_line_id' => ['nullable', 'integer'], 'lines.*.product_id' => ['nullable', 'integer'], 'lines.*.product_code' => ['nullable', 'string'], 'lines.*.description' => ['required', 'string'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_id' => ['nullable', 'integer'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0'], 'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'], 'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0'], 'lines.*.line_total' => ['nullable', 'numeric', 'min:0'], 'lines.*.warehouse_id' => ['nullable', 'integer'], 'lines.*.department_id' => ['nullable', 'integer'], 'lines.*.project_id' => ['nullable', 'integer']];
    }
}
