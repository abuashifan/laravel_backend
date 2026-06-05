<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashReceiptLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'cash_receipt_lines';
    protected $guarded = [];
    protected $casts = ['metadata' => 'array'];

    public function cashReceipt(): BelongsTo { return $this->belongsTo(CashReceipt::class, 'cash_receipt_id'); }
    public function account(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'account_id'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class, 'department_id'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class, 'project_id'); }
}

