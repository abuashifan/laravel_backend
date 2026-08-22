<?php

namespace App\Modules\Budget\Models;

use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Project;
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
        'department_id',
        'project_id',
        'period_month',
        'direction',
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
        'department_name',
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

    protected function departmentName(): Attribute
    {
        return Attribute::get(fn () => $this->department?->name);
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

    /**
     * Departemen di sini adalah *dimensi* baris, bukan pemilik dokumen.
     * Pemiliknya tetap `submission->department_id` — itulah unit persetujuan.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
