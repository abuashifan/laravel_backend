<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FiscalYearClosing extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'fiscal_year_closings';

    protected $fillable = [
        'fiscal_year_id',
        'closed_by_user_id',
        'reopened_by_user_id',
        'closing_reference',
        'retained_earnings_account_id',
        'retained_earnings_amount',
        'closing_notes',
        'closed_at',
        'reopened_at',
        'status',
        'metadata',
    ];

    protected $casts = [
        'fiscal_year_id' => 'integer',
        'closed_by_user_id' => 'integer',
        'reopened_by_user_id' => 'integer',
        'retained_earnings_account_id' => 'integer',
        'retained_earnings_amount' => 'decimal:8',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'metadata' => 'array',
    ];
}

