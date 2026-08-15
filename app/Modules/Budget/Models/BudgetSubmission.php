<?php

namespace App\Modules\Budget\Models;

use App\Modules\MasterData\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BudgetSubmission extends Model
{
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $table = 'budget_submissions';

    protected $fillable = [
        'company_id',
        'budget_period_id',
        'parent_submission_id',
        'department_id',
        'status',
        'revision_number',
        'version_no',
        'is_active',
        'revision_reason',
        'submitted_by_id',
        'submitted_at',
        'approved_by_head_id',
        'approved_by_head_at',
        'approved_by_finance_id',
        'approved_by_finance_at',
        'rejected_by_id',
        'rejected_at',
        'rejection_note',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'revision_number' => 'integer',
        'version_no' => 'integer',
        'is_active' => 'boolean',
        'submitted_at' => 'datetime',
        'approved_by_head_at' => 'datetime',
        'approved_by_finance_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(BudgetPeriod::class, 'budget_period_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'budget_submission_id');
    }

    /**
     * Versi sebelumnya. Revisi tidak menimpa: ia membuat submission baru yang
     * menunjuk pendahulunya, sehingga baris anggaran lama tetap utuh.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_submission_id');
    }

    /** Versi-versi yang lahir langsung dari submission ini. */
    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_submission_id');
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Versi yang berlaku — inilah yang dipakai laporan dan peringatan
     * over-budget. Hanya submission approved yang pernah ditandai aktif.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
