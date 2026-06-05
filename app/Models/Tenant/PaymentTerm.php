<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentTerm extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'payment_terms';

    protected $fillable = [
        'code',
        'name',
        'days',
        'is_custom',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'days' => 'integer',
        'is_custom' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
