<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PosSupplier extends Model
{
    protected $connection = 'pos';

    protected $table = 'pos_suppliers';

    protected $fillable = ['name', 'phone', 'address', 'notes', 'payable_balance', 'is_active'];

    protected $casts = ['payable_balance' => 'float', 'is_active' => 'boolean'];
}
