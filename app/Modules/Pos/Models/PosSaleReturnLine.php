<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PosSaleReturnLine extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_sale_return_lines';

    protected $fillable = ['return_id', 'sale_line_id', 'product_id', 'product_name', 'quantity', 'amount', 'unit_cost', 'restock'];

    protected $casts = ['return_id' => 'integer', 'sale_line_id' => 'integer', 'product_id' => 'integer', 'quantity' => 'float', 'amount' => 'float', 'unit_cost' => 'float', 'restock' => 'boolean'];
}
