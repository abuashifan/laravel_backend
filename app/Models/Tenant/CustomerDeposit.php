<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDeposit extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'customer_deposits';
    protected $guarded = [];
    protected $casts = ['deposit_date' => 'date', 'metadata' => 'array', 'posted_at' => 'datetime', 'voided_at' => 'datetime'];

    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Contact::class, 'customer_id'); }
}
