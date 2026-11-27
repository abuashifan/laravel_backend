<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosGudangReceipt extends Model
{
    public const STATUSES = ['pending', 'received', 'discrepancy'];

    protected $connection = 'pos';

    protected $table = 'pos_gudang_receipts';

    protected $fillable = ['receipt_key', 'number', 'source_name', 'shipped_at', 'status', 'note', 'received_by', 'received_by_name', 'received_at'];

    protected $casts = ['shipped_at' => 'datetime', 'received_at' => 'datetime', 'received_by' => 'integer'];

    public function lines(): HasMany
    {
        return $this->hasMany(PosGudangReceiptLine::class, 'receipt_id')->orderBy('id');
    }
}
