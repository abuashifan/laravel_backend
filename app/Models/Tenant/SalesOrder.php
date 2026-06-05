<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'sales_orders';
    protected $guarded = [];
    protected $casts = [
        'order_date' => 'date',
        'is_taxable' => 'boolean',
        'tax_included' => 'boolean',
        'has_down_payment' => 'boolean',
        'metadata' => 'array',
        'approved_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function lines(): HasMany { return $this->hasMany(SalesOrderLine::class, 'sales_order_id')->orderBy('sort_order'); }
    public function customer(): BelongsTo { return $this->belongsTo(Contact::class, 'customer_id'); }
    public function quotation(): BelongsTo { return $this->belongsTo(SalesQuotation::class, 'quotation_id'); }
    public function deposits(): HasMany { return $this->hasMany(CustomerDeposit::class, 'sales_order_id'); }
}
