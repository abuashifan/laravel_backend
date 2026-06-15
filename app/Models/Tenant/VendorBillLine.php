<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorBillLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'vendor_bill_lines';
    protected $guarded = [];
    protected $casts = [
        'metadata' => 'array',
        'capitalized_amount' => 'decimal:2',
    ];

    public function bill(): BelongsTo { return $this->belongsTo(VendorBill::class, 'vendor_bill_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function unit(): BelongsTo { return $this->belongsTo(Unit::class, 'unit_id'); }
    public function purchaseOrderLine(): BelongsTo { return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id'); }
    public function goodsReceiptLine(): BelongsTo { return $this->belongsTo(GoodsReceiptLine::class, 'goods_receipt_line_id'); }
    public function expenseAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'expense_account_id'); }
}
