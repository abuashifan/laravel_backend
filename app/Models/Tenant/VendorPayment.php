<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorPayment extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'vendor_payments';
    protected $guarded = [];
    protected $casts = [
        'payment_date' => 'date',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function lines(): HasMany { return $this->hasMany(VendorPaymentLine::class, 'vendor_payment_id'); }
    public function vendor(): BelongsTo { return $this->belongsTo(Contact::class, 'vendor_id'); }
    public function vendorBill(): BelongsTo { return $this->belongsTo(VendorBill::class, 'vendor_bill_id'); }

    public function cashBankAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'cash_bank_account_id'); }
}
