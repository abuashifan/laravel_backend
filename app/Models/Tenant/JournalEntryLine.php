<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'journal_entry_lines';

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'department_id',
        'project_id',
        'description',
        'debit',
        'credit',
        'line_order',
        'metadata',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'line_order' => 'integer',
        'metadata' => 'array',
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function hasDepartment(): bool
    {
        return (bool) $this->department_id;
    }

    public function hasProject(): bool
    {
        return (bool) $this->project_id;
    }

    public function isDebit(): bool
    {
        return (float) $this->debit > 0;
    }

    public function isCredit(): bool
    {
        return (float) $this->credit > 0;
    }

    public function amount(): float
    {
        return $this->isDebit() ? (float) $this->debit : (float) $this->credit;
    }

    public function hasBothDebitAndCredit(): bool
    {
        return (float) $this->debit > 0 && (float) $this->credit > 0;
    }
}
