<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProformaInvoiceLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'proforma_invoice_lines';
    protected $guarded = [];
    protected $casts = ['metadata' => 'array'];

    public function proformaInvoice(): BelongsTo { return $this->belongsTo(ProformaInvoice::class, 'proforma_invoice_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function unit(): BelongsTo { return $this->belongsTo(Unit::class, 'unit_id'); }
}
