<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosGudangReceiptLine extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_gudang_receipt_lines';

    protected $fillable = ['receipt_id', 'product_id', 'sku', 'name', 'unit', 'expected_quantity', 'actual_quantity'];

    protected $casts = ['receipt_id' => 'integer', 'product_id' => 'integer', 'expected_quantity' => 'float', 'actual_quantity' => 'float'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PosGudangReceipt::class, 'receipt_id');
    }
}
