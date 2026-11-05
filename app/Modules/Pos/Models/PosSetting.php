<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PosSetting extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_settings';

    protected $fillable = ['key', 'value'];

    protected $casts = [];
}
