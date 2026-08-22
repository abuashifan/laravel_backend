<?php

namespace App\Modules\Purchase\Models;

use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Models\Warehouse;
use Database\Factories\Tenant\GoodsReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return GoodsReceiptFactory::new();
    }

    protected $connection = 'tenant';

    protected $table = 'goods_receipts';

    protected $guarded = [];

    protected $casts = [
        'receipt_date' => 'date',
        'metadata' => 'array',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'goods_receipt_id')->orderBy('sort_order');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'vendor_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
