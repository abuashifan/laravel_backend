<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * Flatten loaded relation labels into the line payload so the frontend
     * BudgetLine contract (flat account_code/account_name/project_name) is met
     * without a dedicated API Resource (codebase convention).
     */
    protected $appends = [
        'account_code',
        'account_name',
        'project_name',
    ];

    protected function accountCode(): Attribute
    {
        return Attribute::get(fn () => $this->account?->account_code);
    }

    protected function accountName(): Attribute
    {
        return Attribute::get(fn () => $this->account?->account_name);
    }

    protected function projectName(): Attribute
    {
        return Attribute::get(fn () => $this->project?->name);
    }

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
