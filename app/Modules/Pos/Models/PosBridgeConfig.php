<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PosBridgeConfig extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_bridge_config';

    protected $fillable = ['enabled', 'start_date', 'post_cogs', 'account_map', 'connected_by', 'connected_at', 'disconnected_at'];

    protected $casts = [
        'enabled' => 'boolean',
        'start_date' => 'date:Y-m-d',
        'post_cogs' => 'boolean',
        'account_map' => 'array',
        'connected_by' => 'integer',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
    ];
}
