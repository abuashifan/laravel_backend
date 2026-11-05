<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PosShift extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_shifts';

    protected $fillable = ['user_id', 'user_name', 'opened_at', 'opening_cash', 'closed_at', 'expected_cash', 'counted_cash', 'cash_difference', 'notes', 'status'];

    protected $casts = ['user_id' => 'integer', 'opened_at' => 'datetime', 'closed_at' => 'datetime', 'opening_cash' => 'float', 'expected_cash' => 'float', 'counted_cash' => 'float', 'cash_difference' => 'float'];
}
