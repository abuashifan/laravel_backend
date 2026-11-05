<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;

class PosCustomer extends Model
{
    protected $connection = 'tenant';

    protected $table = 'pos_customers';

    protected $fillable = ['name', 'phone', 'address', 'notes', 'credit_balance', 'is_active'];

    protected $casts = ['credit_balance' => 'float', 'is_active' => 'boolean'];
}
