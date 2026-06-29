<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLine extends Model
{
    protected $connection = 'tenant';

    protected $table = 'budget_lines';

    protected $fillable = [
        'budget_submission_id',
        'account_id',
        'project_id',
        'period',
        'amount',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(BudgetSubmission::class, 'budget_submission_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
