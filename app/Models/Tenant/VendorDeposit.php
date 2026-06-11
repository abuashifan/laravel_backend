<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorDeposit extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'vendor_deposits';
    protected $guarded = [];
    protected $appends = ['vendor_number', 'vendor_name', 'cash_bank_account_name'];
    protected $casts = [
        'deposit_date' => 'date',
        'metadata' => 'array',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function vendor(): BelongsTo { return $this->belongsTo(Contact::class, 'vendor_id'); }
    public function cashBankAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'cash_bank_account_id'); }

    public function getVendorNumberAttribute(): ?string
    {
        $vendor = $this->vendor;

        return $vendor?->contact_number
            ?? $vendor?->contact_code
            ?? $vendor?->code
            ?? null;
    }

    public function getVendorNameAttribute(): ?string
    {
        return $this->vendor?->name;
    }

    public function getCashBankAccountNameAttribute(): ?string
    {
        $account = $this->cashBankAccount;

        if (! $account) {
            return null;
        }

        return trim(($account->account_code ? $account->account_code.' - ' : '').$account->account_name);
    }
}
