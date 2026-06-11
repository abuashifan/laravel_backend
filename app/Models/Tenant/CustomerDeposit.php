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
    protected $appends = ['customer_number', 'customer_name', 'cash_bank_account_name'];

    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Contact::class, 'customer_id'); }
    public function cashBankAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'cash_bank_account_id'); }

    public function getCustomerNumberAttribute(): ?string
    {
        $customer = $this->customer;

        return $customer?->contact_number
            ?? $customer?->contact_code
            ?? $customer?->code
            ?? null;
    }

    public function getCustomerNameAttribute(): ?string
    {
        return $this->customer?->name;
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
