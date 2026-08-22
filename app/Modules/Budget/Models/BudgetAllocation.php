<?php

namespace App\Modules\Budget\Models;

use App\Modules\MasterData\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetAllocation extends Model
{
    protected $connection = 'tenant';

    protected $table = 'budget_allocations';

    protected $fillable = [
        'company_id',
        'budget_period_id',
        'department_id',
        'parent_allocation_id',
        'amount',
        'notes',
        'created_by',
        'approved_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(BudgetPeriod::class, 'budget_period_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_allocation_id');
    }

    /** Pagu departemen di bawah pagu ini. Hanya berarti untuk root (departemen tidak dapat anak — Gap F). */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_allocation_id');
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function isRoot(): bool
    {
        return $this->department_id === null;
    }
}
