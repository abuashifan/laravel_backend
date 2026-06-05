<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashPaymentLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'cash_payment_lines';
    protected $guarded = [];
    protected $casts = ['metadata' => 'array'];

    public function cashPayment(): BelongsTo { return $this->belongsTo(CashPayment::class, 'cash_payment_id'); }
    public function account(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'account_id'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class, 'department_id'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class, 'project_id'); }
}

