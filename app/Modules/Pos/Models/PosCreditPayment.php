<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosCreditPayment extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_credit_payments';

    protected $fillable = ['number', 'customer_id', 'shift_id', 'amount', 'method', 'paid_at', 'user_id', 'user_name', 'notes'];

    protected $casts = ['customer_id' => 'integer', 'shift_id' => 'integer', 'amount' => 'float', 'paid_at' => 'datetime', 'user_id' => 'integer'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(PosCustomer::class, 'customer_id');
    }
}
