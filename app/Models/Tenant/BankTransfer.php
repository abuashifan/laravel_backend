<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransfer extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'bank_transfers';
    protected $guarded = [];
    protected $casts = ['transfer_date' => 'date', 'posted_at' => 'datetime', 'voided_at' => 'datetime', 'metadata' => 'array'];

    public function fromCashBankAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'from_cash_bank_account_id'); }
    public function toCashBankAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'to_cash_bank_account_id'); }
}

