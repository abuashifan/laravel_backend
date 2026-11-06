<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosPurchase extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_purchases';

    protected $fillable = ['number', 'supplier_id', 'received_at', 'user_id', 'user_name', 'total', 'paid_amount', 'payable_amount', 'payment_method', 'reference', 'notes'];

    protected $casts = ['supplier_id' => 'integer', 'received_at' => 'datetime', 'user_id' => 'integer', 'total' => 'float', 'paid_amount' => 'float', 'payable_amount' => 'float'];

    public function lines(): HasMany
    {
        return $this->hasMany(PosPurchaseLine::class, 'purchase_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(PosSupplier::class, 'supplier_id');
    }
}
