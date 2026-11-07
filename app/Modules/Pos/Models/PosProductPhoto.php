<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PosProductPhoto extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_product_photos';

    protected $fillable = ['product_id', 'data', 'bytes'];

    protected $casts = ['product_id' => 'integer', 'bytes' => 'integer'];
}
