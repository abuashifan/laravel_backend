<?php

namespace App\Modules\CashBank\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceDisbursement extends Model
{
    protected $connection = 'tenant';

    protected $guarded = [];

    protected $casts = [
        'source_lines' => 'array',
        'activity_date' => 'date:Y-m-d',
        'disbursed_on' => 'date:Y-m-d',
        'amount_minor' => 'integer',
    ];

    public function cashPayment(): BelongsTo
    {
        return $this->belongsTo(CashPayment::class);
    }
}
