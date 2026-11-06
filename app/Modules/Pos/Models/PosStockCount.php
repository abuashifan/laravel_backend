<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosStockCount extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_stock_counts';

    protected $fillable = ['number', 'counted_at', 'user_id', 'user_name', 'difference_value', 'notes'];

    protected $casts = ['counted_at' => 'datetime', 'user_id' => 'integer', 'difference_value' => 'float'];

    public function lines(): HasMany
    {
        return $this->hasMany(PosStockCountLine::class, 'stock_count_id');
    }
}
