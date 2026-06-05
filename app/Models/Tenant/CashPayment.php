<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashPayment extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'cash_payments';
    protected $guarded = [];
    protected $casts = ['payment_date' => 'date', 'posted_at' => 'datetime', 'voided_at' => 'datetime', 'metadata' => 'array'];

    public function lines(): HasMany { return $this->hasMany(CashPaymentLine::class, 'cash_payment_id'); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class, 'contact_id'); }
    public function cashBankAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'cash_bank_account_id'); }
}

