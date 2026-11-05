<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosSale extends Model
{
    protected $connection = 'tenant';

    protected $table = 'pos_sales';

    protected $fillable = ['number', 'shift_id', 'user_id', 'user_name', 'customer_id', 'sold_at', 'subtotal', 'discount_amount', 'total', 'payment_method', 'paid_amount', 'change_amount', 'credit_amount', 'receipt_mode', 'status', 'voided_at', 'voided_by', 'void_reason', 'notes'];

    protected $casts = ['shift_id' => 'integer', 'user_id' => 'integer', 'customer_id' => 'integer', 'sold_at' => 'datetime', 'voided_at' => 'datetime', 'subtotal' => 'float', 'discount_amount' => 'float', 'total' => 'float', 'paid_amount' => 'float', 'change_amount' => 'float', 'credit_amount' => 'float'];

    public function lines(): HasMany
    {
        return $this->hasMany(PosSaleLine::class, 'sale_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(PosCustomer::class, 'customer_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'shift_id');
    }
}
