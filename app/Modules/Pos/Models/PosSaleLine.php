<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PosSaleLine extends Model
{
    protected $connection = 'tenant';

    protected $table = 'pos_sale_lines';

    protected $fillable = ['sale_id', 'product_id', 'product_name', 'sku', 'unit', 'quantity', 'unit_price', 'discount_amount', 'line_total', 'unit_cost'];

    protected $casts = ['sale_id' => 'integer', 'product_id' => 'integer', 'quantity' => 'float', 'unit_price' => 'float', 'discount_amount' => 'float', 'line_total' => 'float', 'unit_cost' => 'float'];
}
