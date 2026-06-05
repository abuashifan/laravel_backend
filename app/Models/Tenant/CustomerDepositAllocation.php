<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDepositAllocation extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'customer_deposit_allocations';
    protected $guarded = [];
    protected $casts = [
        'allocation_date' => 'date',
        'voided_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function customerDeposit(): BelongsTo
    {
        return $this->belongsTo(CustomerDeposit::class, 'customer_deposit_id');
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}
