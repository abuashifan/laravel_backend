<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PosStockCountLine extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_stock_count_lines';

    protected $fillable = ['stock_count_id', 'product_id', 'product_name', 'system_quantity', 'counted_quantity', 'difference', 'unit_cost'];

    protected $casts = ['stock_count_id' => 'integer', 'product_id' => 'integer', 'system_quantity' => 'float', 'counted_quantity' => 'float', 'difference' => 'float', 'unit_cost' => 'float'];
}
