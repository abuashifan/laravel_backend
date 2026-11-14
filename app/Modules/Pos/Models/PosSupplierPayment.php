<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosSupplierPayment extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_supplier_payments';

    protected $fillable = ['number', 'supplier_id', 'amount', 'method', 'paid_at', 'user_id', 'user_name', 'notes'];

    protected $casts = ['supplier_id' => 'integer', 'amount' => 'float', 'paid_at' => 'datetime', 'user_id' => 'integer'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(PosSupplier::class, 'supplier_id');
    }
}
