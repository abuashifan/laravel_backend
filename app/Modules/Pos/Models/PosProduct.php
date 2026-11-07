<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosProduct extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_products';

    protected $fillable = ['sku', 'barcode', 'name', 'category_id', 'unit', 'price', 'cost', 'track_stock', 'stock', 'is_active', 'price_tiers', 'photo_thumb'];

    protected $casts = ['category_id' => 'integer', 'price' => 'float', 'cost' => 'float', 'track_stock' => 'boolean', 'stock' => 'float', 'is_active' => 'boolean', 'price_tiers' => 'array'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PosCategory::class, 'category_id');
    }
}
