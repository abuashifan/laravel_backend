<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PosPurchaseLine extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_purchase_lines';

    protected $fillable = ['purchase_id', 'product_id', 'product_name', 'unit', 'quantity', 'unit_cost', 'line_total'];

    protected $casts = ['purchase_id' => 'integer', 'product_id' => 'integer', 'quantity' => 'float', 'unit_cost' => 'float', 'line_total' => 'float'];
}
