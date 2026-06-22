<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankReconciliation extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'bank_reconciliations';

    protected $guarded = [];

    protected $casts = [
        'statement_start_date' => 'date',
        'statement_end_date' => 'date',
        'posted_at' => 'datetime',
        'reopened_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(BankReconciliationLine::class, 'bank_reconciliation_id');
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'cash_bank_account_id');
    }
}
