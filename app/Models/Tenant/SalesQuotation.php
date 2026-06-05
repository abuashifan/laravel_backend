<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesQuotation extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'sales_quotations';

    protected $guarded = [];

    protected $casts = [
        'quotation_date' => 'date',
        'valid_until' => 'date',
        'is_taxable' => 'boolean',
        'tax_included' => 'boolean',
        'metadata' => 'array',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'converted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesQuotationLine::class, 'sales_quotation_id')->orderBy('sort_order');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'salesperson_id');
    }
}
