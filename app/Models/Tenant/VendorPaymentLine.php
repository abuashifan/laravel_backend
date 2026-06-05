<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorPaymentLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'vendor_payment_lines';
    protected $guarded = [];
    protected $casts = ['metadata' => 'array'];

    public function vendorPayment(): BelongsTo { return $this->belongsTo(VendorPayment::class, 'vendor_payment_id'); }
    public function vendorBill(): BelongsTo { return $this->belongsTo(VendorBill::class, 'vendor_bill_id'); }
}
