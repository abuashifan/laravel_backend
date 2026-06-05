<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationLine extends Model
{
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'bank_reconciliation_lines';
    protected $guarded = [];
    protected $casts = [
        'journal_date' => 'date',
        'is_cleared' => 'boolean',
        'cleared_date' => 'date',
        'metadata' => 'array',
    ];

    public function reconciliation(): BelongsTo { return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function journalEntryLine(): BelongsTo { return $this->belongsTo(JournalEntryLine::class, 'journal_entry_line_id'); }
}

