<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorDepositAllocation extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'vendor_deposit_allocations';
    protected $guarded = [];
    protected $casts = [
        'allocation_date' => 'date',
        'voided_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function vendorDeposit(): BelongsTo { return $this->belongsTo(VendorDeposit::class, 'vendor_deposit_id'); }
    public function vendorBill(): BelongsTo { return $this->belongsTo(VendorBill::class, 'vendor_bill_id'); }
}
