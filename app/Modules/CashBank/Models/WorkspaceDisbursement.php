<?php

namespace App\Modules\CashBank\Models;

use App\Modules\MasterData\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceDisbursement extends Model
{
    protected $connection = 'tenant';

    protected $guarded = [];

    protected $casts = [
        'source_lines' => 'array',
        'activity_date' => 'date:Y-m-d',
        'disbursed_on' => 'date:Y-m-d',
        'amount_minor' => 'integer',
        'closed_at' => 'datetime',
    ];

    public function tranches(): HasMany
    {
        return $this->hasMany(WorkspaceDisbursementTranche::class)->orderBy('sequence');
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function cashPayment(): BelongsTo
    {
        return $this->belongsTo(CashPayment::class);
    }
}
