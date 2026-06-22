<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReceipt extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'sales_receipts';

    protected $guarded = [];

    protected $casts = ['receipt_date' => 'date', 'posted_at' => 'datetime', 'voided_at' => 'datetime', 'metadata' => 'array'];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesReceiptLine::class, 'sales_receipt_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'cash_bank_account_id');
    }
}
