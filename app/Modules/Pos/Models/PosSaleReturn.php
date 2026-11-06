<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosSaleReturn extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_sale_returns';

    protected $fillable = ['number', 'sale_id', 'shift_id', 'user_id', 'user_name', 'customer_id', 'returned_at', 'total', 'refund_method', 'reason', 'tax_amount'];

    protected $casts = ['sale_id' => 'integer', 'shift_id' => 'integer', 'user_id' => 'integer', 'customer_id' => 'integer', 'returned_at' => 'datetime', 'total' => 'float', 'tax_amount' => 'float'];

    public function lines(): HasMany
    {
        return $this->hasMany(PosSaleReturnLine::class, 'return_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'sale_id');
    }
}
