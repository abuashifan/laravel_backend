<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BudgetPeriod extends Model
{
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $table = 'budget_periods';

    protected $fillable = [
        'company_id',
        'name',
        'fiscal_year',
        'period_from',
        'period_to',
        'status',
        'created_by',
    ];

    protected $casts = [
        'fiscal_year' => 'integer',
        'period_from' => 'date',
        'period_to' => 'date',
    ];

    public function submissions(): HasMany
    {
        return $this->hasMany(BudgetSubmission::class, 'budget_period_id');
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
