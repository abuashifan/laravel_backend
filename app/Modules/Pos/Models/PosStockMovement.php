<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PosStockMovement extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_stock_movements';

    protected $fillable = ['product_id', 'type', 'quantity', 'stock_after', 'reference_type', 'reference_id', 'notes', 'user_id'];

    protected $casts = ['product_id' => 'integer', 'quantity' => 'float', 'stock_after' => 'float', 'reference_id' => 'integer', 'user_id' => 'integer'];
}
