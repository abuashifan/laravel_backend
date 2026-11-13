<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PosMemberLimit extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_member_limits';

    protected $fillable = ['user_id', 'max_discount_percent'];

    protected $casts = ['user_id' => 'integer', 'max_discount_percent' => 'float'];
}
